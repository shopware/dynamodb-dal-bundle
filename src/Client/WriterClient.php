<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Client;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Client\Input\DeleteInput;
use Shopware\DynamodbDalBundle\Client\Input\PutInput;
use Shopware\DynamodbDalBundle\Client\Input\TransactWriteInput;
use Shopware\DynamodbDalBundle\Client\Input\UpdateInput;
use Shopware\DynamodbDalBundle\Expression\Contract\ExpressionInterface;
use Shopware\DynamodbDalBundle\Expression\ExpressionCompiledResult;
use Shopware\DynamodbDalBundle\Expression\ExpressionCompiler;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\EntityDefinitionRegistry;
use Shopware\DynamodbDalBundle\Serializer\SerializedResult;
use Shopware\DynamodbDalBundle\Serializer\Serializer;
use AsyncAws\DynamoDb\DynamoDbClient;
use AsyncAws\DynamoDb\Exception\TransactionCanceledException;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;
use AsyncAws\DynamoDb\ValueObject\CancellationReason;
use AsyncAws\DynamoDb\ValueObject\TransactWriteItem;
use AsyncAws\DynamoDb\ValueObject\WriteRequest;

/**
 * A lone input takes the single-item API, which is cheaper than a batch of one. Several inputs
 * are batched, falling back to `TransactWriteItem` where `BatchWriteItem` cannot serve them.
 */
class WriterClient
{
    private const int BATCH_WRITE_LIMIT = 25;

    private const int TRANSACT_WRITE_LIMIT = 100;

    private const int TRANSACT_WRITE_CONFLICT_MAX_ATTEMPTS = 3;

    private const int TRANSACT_WRITE_CONFLICT_BASE_DELAY_MICROSECONDS = 20_000;

    /**
     * @see TransactionCanceledException for the full list of cancellation reason codes.
     */
    private const array TRANSACT_WRITE_CONFLICT_RETRYABLE_CODES = ['None', 'TransactionConflict'];

    /**
     * @internal
     */
    public function __construct(
        protected readonly DynamoDbClient $client,
        protected readonly Serializer $serializer,
        protected readonly ExpressionCompiler $expressionCompiler,
        protected readonly EntityDefinitionRegistry $definitionRegistry,
    ) {
    }

    /**
     * Writes the given entities, applying the serialized result back onto each once the request succeeds so
     * normalization-generated values (e.g. a `Uuid::v7()` key or `createdAt`) are reflected on the entity.
     *
     * This operation is not atomic, for that use {@see self::transactWrite} instead.
     * If any input contains a condition expression, {@see self::transactWrite} is used instead of a batch
     * write, since `BatchWriteItem` does not support condition expressions.
     * Batch writes are limited to 25 operations per batch.
     *
     * @template Entity of AbstractEntity
     *
     * @param EntityDefinition<Entity> $definition
     * @param PutInput<Entity> ...$inputs
     */
    public function put(EntityDefinition $definition, PutInput ...$inputs): void
    {
        if (\count($inputs) === 1) {
            $result = $this->serializer->serialize($definition, $inputs[0]->entity);
            $expression = $this->compileExpression($definition, $inputs[0]->conditionExpression);

            $this->client->putItem([
                'TableName' => $definition->getTable(),
                ...$result->getPutExpression(),
                ...$expression->getExpression('condition'),
                ...$expression->getExpressionAttributes(),
            ])->resolve();

            $result->apply($inputs[0]->entity);

            return;
        }

        if ($this->hasConditionExpressions(...$inputs)) {
            $this->transactWrite(new TransactWriteInput([$definition->getClass() => $inputs]));

            return;
        }

        $this->batchWriteItem($definition, ...$inputs);
    }

    /**
     * Updates the given items. When an input is keyed by an entity the serialized result is applied back onto it
     * once the request succeeds (see {@see self::put()}) so normalization-generated values are reflected.
     *
     * This operation is atomic and uses {@see self::transactWrite} for batch writes, since
     * `BatchWriteItem` cannot update items.
     * Batch writes are limited to 25 operations per batch.
     *
     * @template Entity of AbstractEntity
     *
     * @param EntityDefinition<Entity> $definition
     * @param UpdateInput<Entity> ...$inputs
     */
    public function update(EntityDefinition $definition, UpdateInput ...$inputs): void
    {
        if (\count($inputs) === 1) {
            $result = $this->serializer->serialize($definition, $inputs[0]->fields);
            $expression = $this->compileExpression($definition, $inputs[0]->conditionExpression);

            $this->client->updateItem([
                'TableName' => $definition->getTable(),
                'Key' => $this->serializer->serializeKey($definition, $inputs[0]->key),
                ...$expression->getExpression('condition'),
                ...$this->merge($result->getUpdateExpression(), $expression->getExpressionAttributes()),
            ])->resolve();

            if ($inputs[0]->key instanceof AbstractEntity) {
                $result->apply($inputs[0]->key);
            }

            return;
        }

        $this->transactWrite(new TransactWriteInput([$definition->getClass() => $inputs]));
    }

    /**
     * This operation is generally not atomic, for that use {@see self::transactWrite} instead.
     * If any input contains a condition expression, {@see self::transactWrite} is used instead of a batch
     * write, since `BatchWriteItem` does not support condition expressions.
     * Transactional writes are limited to 100 operations per batch.
     */
    public function delete(EntityDefinition $definition, DeleteInput ...$inputs): void
    {
        if (\count($inputs) === 1) {
            $expression = $this->compileExpression($definition, $inputs[0]->conditionExpression);

            $this->client->deleteItem([
                'TableName' => $definition->getTable(),
                'Key' => $this->serializer->serializeKey($definition, $inputs[0]->key),
                ...$expression->getExpression('condition'),
                ...$expression->getExpressionAttributes(),
            ])->resolve();

            return;
        }

        if ($this->hasConditionExpressions(...$inputs)) {
            $this->transactWrite(new TransactWriteInput([$definition->getClass() => $inputs]));
        } else {
            $this->batchWriteItem($definition, ...$inputs);
        }
    }

    /**
     * Write entities to different tables as one transaction.
     * Once the transaction succeeds the serialized result of every put, and of every update keyed by an entity,
     * is applied back onto that entity (see {@see self::put()}).
     * Transactional writes are limited to 100 operations per batch.
     * A `TransactionConflict` cancellation is retried with backoff; any other cancellation reason is rethrown.
     *
     * @template Entity of AbstractEntity
     *
     * @param TransactWriteInput<Entity> $input
     */
    public function transactWrite(TransactWriteInput $input): void
    {
        /** @var list<array{SerializedResult, AbstractEntity}> $applies */
        $applies = [];
        $writeRequests = [];

        foreach ($input->operations as $entityClass => $operations) {
            $definition = $this->definitionRegistry->getByEntityClass($entityClass);

            foreach ($operations as $operation) {
                if ($operation instanceof DeleteInput) {
                    $expression = $this->compileExpression($definition, $operation->conditionExpression);

                    $writeRequests[] = new TransactWriteItem(['Delete' => [
                        'TableName' => $definition->getTable(),
                        'Key' => $this->serializer->serializeKey($definition, $operation->key),
                        ...$expression->getExpression('condition'),
                        ...$expression->getExpressionAttributes(),
                    ]]);
                }

                if ($operation instanceof UpdateInput) {
                    $result = $this->serializer->serialize($definition, $operation->fields);
                    $expression = $this->compileExpression($definition, $operation->conditionExpression);

                    $writeRequests[] = new TransactWriteItem(['Update' => [
                        'TableName' => $definition->getTable(),
                        'Key' => $this->serializer->serializeKey($definition, $operation->key),
                        ...$expression->getExpression('condition'),
                        ...$this->merge($result->getUpdateExpression(), $expression->getExpressionAttributes()),
                    ]]);

                    if ($operation->key instanceof AbstractEntity) {
                        $applies[] = [$result, $operation->key];
                    }
                }

                if ($operation instanceof PutInput) {
                    $result = $this->serializer->serialize($definition, $operation->entity);
                    $expression = $this->compileExpression($definition, $operation->conditionExpression);

                    $writeRequests[] = new TransactWriteItem(['Put' => [
                        'TableName' => $definition->getTable(),
                        ...$result->getPutExpression(),
                        ...$expression->getExpression('condition'),
                        ...$expression->getExpressionAttributes(),
                    ]]);

                    $applies[] = [$result, $operation->entity];
                }
            }
        }

        foreach (array_chunk($writeRequests, self::TRANSACT_WRITE_LIMIT) as $chunk) {
            $this->transactWriteChunkWithConflictRetry($chunk);
        }

        foreach ($applies as [$result, $entity]) {
            $result->apply($entity);
        }
    }

    /**
     * @param TransactWriteItem[] $chunk
     */
    private function transactWriteChunkWithConflictRetry(array $chunk): void
    {
        for ($attempt = 1;; ++$attempt) {
            try {
                $this->client->transactWriteItems(['TransactItems' => $chunk])->resolve();

                return;
            } catch (TransactionCanceledException $exception) {
                if ($attempt >= self::TRANSACT_WRITE_CONFLICT_MAX_ATTEMPTS || !$this->isTransactionConflict($exception)) {
                    throw $exception;
                }

                usleep(self::TRANSACT_WRITE_CONFLICT_BASE_DELAY_MICROSECONDS * $attempt);
            }
        }
    }

    private function isTransactionConflict(TransactionCanceledException $exception): bool
    {
        $reasons = $exception->getCancellationReasons();

        return $reasons !== []
            && array_any($reasons, static fn (CancellationReason $reason): bool => $reason->getCode() === 'TransactionConflict')
            && array_all(
                $reasons,
                static fn (CancellationReason $reason): bool => \in_array($reason->getCode(), self::TRANSACT_WRITE_CONFLICT_RETRYABLE_CODES, true),
            );
    }

    /**
     * @template Entity of AbstractEntity
     *
     * @param EntityDefinition<Entity> $definition
     * @param DeleteInput<Entity>|PutInput<Entity> ...$inputs
     */
    private function batchWriteItem(EntityDefinition $definition, DeleteInput|PutInput ...$inputs): void
    {
        /** @var list<array{SerializedResult, AbstractEntity}> $puts */
        $puts = [];
        $writeRequests = [];

        foreach ($inputs as $input) {
            if ($input instanceof DeleteInput) {
                $writeRequests[] = new WriteRequest([
                    'DeleteRequest' => ['Key' => $this->serializer->serializeKey($definition, $input->key)],
                ]);
            }

            if ($input instanceof PutInput) {
                $result = $this->serializer->serialize($definition, $input->entity);

                $writeRequests[] = new WriteRequest(['PutRequest' => $result->getPutExpression()]);

                $puts[] = [$result, $input->entity];
            }
        }

        foreach (array_chunk($writeRequests, self::BATCH_WRITE_LIMIT) as $chunk) {
            $requests = $chunk;

            do {
                $result = $this->client->batchWriteItem(['RequestItems' => [$definition->getTable() => $requests]]);

                // getUnprocessedItems() is keyed by table; resubmit just this table's leftovers (empty = done)
                $requests = $result->getUnprocessedItems()[$definition->getTable()] ?? [];
            } while ($requests);
        }

        foreach ($puts as [$result, $entity]) {
            $result->apply($entity);
        }
    }

    /**
     * @param DeleteInput<AbstractEntity>|PutInput<AbstractEntity>|UpdateInput<AbstractEntity> ...$inputs
     */
    private function hasConditionExpressions(DeleteInput|PutInput|UpdateInput ...$inputs): bool
    {
        return array_any($inputs, static fn ($input): bool => (bool) $input->conditionExpression);
    }

    private function compileExpression(EntityDefinition $definition, ?ExpressionInterface $expression): ExpressionCompiledResult
    {
        if ($expression) {
            return $this->expressionCompiler->compile($definition, $expression);
        }

        return new ExpressionCompiledResult();
    }

    /**
     * @param array{UpdateExpression?: string, ConditionExpression?: string, ExpressionAttributeNames?: array<string, string>, ExpressionAttributeValues?: array<string, AttributeValue>} ...$expressions
     *
     * @return array{UpdateExpression?: string, ConditionExpression?: string, ExpressionAttributeNames?: array<string, string>, ExpressionAttributeValues?: array<string, AttributeValue>}
     */
    private function merge(array ...$expressions): array
    {
        $names = [];
        $values = [];
        foreach ($expressions as $expression) {
            $names = [...$names, ...($expression['ExpressionAttributeNames'] ?? [])];
            $values = [...$values, ...($expression['ExpressionAttributeValues'] ?? [])];
        }

        return array_filter(
            [
                ...array_merge(...$expressions),
                'ExpressionAttributeNames' => $names,
                'ExpressionAttributeValues' => $values,
            ],
            static fn (mixed $value): bool => $value !== [],
        );
    }
}
