<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Client;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Client\Input\DeleteInput;
use Shopware\DynamodbDalBundle\Client\Input\PutInput;
use Shopware\DynamodbDalBundle\Client\Input\RefreshInput;
use Shopware\DynamodbDalBundle\Client\Input\TransactWriteInput;
use Shopware\DynamodbDalBundle\Client\Input\UpdateInput;
use Shopware\DynamodbDalBundle\Exception\DALException;
use Shopware\DynamodbDalBundle\Exception\UnknownEntityDefinitionException;
use Shopware\DynamodbDalBundle\Expression\Contract\FilterInterface;
use Shopware\DynamodbDalBundle\Expression\ExpressionCompiledResult;
use Shopware\DynamodbDalBundle\Expression\ExpressionCompiler;
use Shopware\DynamodbDalBundle\Expression\Filter;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\EntityDefinitionRegistry;
use Shopware\DynamodbDalBundle\Serializer\NormalizerOperation;
use Shopware\DynamodbDalBundle\Serializer\SerializedResult;
use Shopware\DynamodbDalBundle\Serializer\Serializer;
use AsyncAws\Core\Exception\Exception as AsyncAwsException;
use AsyncAws\DynamoDb\DynamoDbClient;
use AsyncAws\DynamoDb\Enum\ReturnValue;
use AsyncAws\DynamoDb\Exception\ConditionalCheckFailedException;
use AsyncAws\DynamoDb\Exception\TransactionCanceledException;
use AsyncAws\DynamoDb\ValueObject\CancellationReason;
use AsyncAws\DynamoDb\ValueObject\TransactWriteItem;
use AsyncAws\DynamoDb\ValueObject\WriteRequest;

/**
 * The write side behind {@see Client}. A lone input takes the single-item API, which is cheaper than a batch
 * of one. Several inputs are batched, falling back to `TransactWriteItem` where `BatchWriteItem` cannot serve
 * them.
 *
 * @internal
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
        protected readonly ReaderClient $reader,
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
     * @param class-string<Entity> $class
     * @param PutInput<Entity> ...$inputs
     *
     * @throws UnknownEntityDefinitionException
     * @throws DALException if an entity or a condition does not serialize
     * @throws ConditionalCheckFailedException for a lone input
     * @throws TransactionCanceledException for several conditional inputs, e.g. when a condition fails
     * @throws AsyncAwsException if a request to DynamoDB fails otherwise
     */
    public function put(string $class, PutInput ...$inputs): void
    {
        $definition = $this->definitionRegistry->getByEntityClass($class);

        if (\count($inputs) === 1) {
            $result = $this->serializer->serialize($definition, $inputs[0]->entity, NormalizerOperation::Put);
            $expression = $this->compileExpression($definition, $inputs[0]->conditionExpression);

            $this->client->putItem([
                'TableName' => $definition->getTable(),
                ...$result->getPutExpression(),
                ...$expression->getExpression('condition'),
                ...$expression->getExpressionAttributes(),
            ])->resolve();

            $this->applyFields($inputs[0]->entity, $definition, $result->getNormalizedFields(), $result->getOperation());

            return;
        }

        if ($this->hasConditionExpressions(...$inputs)) {
            $this->transactWrite(new TransactWriteInput([$class => $inputs]));

            return;
        }

        $this->batchWriteItem($definition, ...$inputs);
    }

    /**
     * Updates the given items. Unlike {@see self::put()}, an update writes only the fields it names.
     * Every update is conditioned on its item existing, so a missing key fails instead of creating a partial item.
     *
     * {@see UpdateInput::$refresh} decides how updates are applied to the existing entity:
     * 1. `null` = best effort of keeping the entity up-to-date. Nested paths and actions will not be applied.
     * 2. `true` = entity changes are applied, if necessary a readback is performed.
     * 3. `false` = entity is not updated at all, even if it is an entity and the update could be applied.
     * Readbacks are only necessary if an update writes a nested path or has an action.
     *
     * This operation is atomic and uses {@see self::transactWrite} for batch writes, since
     * `BatchWriteItem` cannot update items.
     * Transactional writes are limited to 100 operations per batch.
     *
     * @template Entity of AbstractEntity
     *
     * @param class-string<Entity> $class
     * @param UpdateInput<Entity> ...$inputs
     *
     * @throws UnknownEntityDefinitionException
     * @throws DALException if an update, a key or a condition does not serialize, an update has nothing to write, or a stored item does not deserialize
     * @throws ConditionalCheckFailedException for a lone input, when the item does not exist or the condition fails
     * @throws TransactionCanceledException for several inputs, e.g. when an item does not exist or a condition fails
     * @throws AsyncAwsException if a request to DynamoDB fails otherwise
     */
    public function update(string $class, UpdateInput ...$inputs): void
    {
        $definition = $this->definitionRegistry->getByEntityClass($class);

        if (\count($inputs) === 1) {
            [, $update] = $this->expressionCompiler->compileUpdate($definition, $inputs[0]->update);
            $condition = $this->compileUpdateCondition($definition, $inputs[0]);
            // update entity if the caller did not explicitly disallowed it
            $entity = $inputs[0]->refresh !== false && $inputs[0]->key instanceof AbstractEntity ? $inputs[0]->key : null;

            $output = $this->client->updateItem([
                'TableName' => $definition->getTable(),
                'Key' => $this->serializer->serializeKey($definition, $inputs[0]->key),
                ...($entity ? ['ReturnValues' => ReturnValue::ALL_NEW] : []),
                ...$update->getExpression('update'),
                ...$condition->getExpression('condition'),
                ...$update->getExpressionAttributes($condition),
            ]);

            $output->resolve();

            if ($entity) {
                $this->serializer->deserialize($definition, $output->getAttributes(), $entity);
            }

            return;
        }

        $this->transactWrite(new TransactWriteInput([$class => $inputs]));
    }

    /**
     * This operation is generally not atomic, for that use {@see self::transactWrite} instead.
     * If any input contains a condition expression, {@see self::transactWrite} is used instead of a batch
     * write, since `BatchWriteItem` does not support condition expressions.
     * Transactional writes are limited to 100 operations per batch.
     *
     * @template Entity of AbstractEntity
     *
     * @param class-string<Entity> $class
     * @param DeleteInput<Entity> ...$inputs
     *
     * @throws UnknownEntityDefinitionException
     * @throws DALException if a key or a condition does not serialize
     * @throws ConditionalCheckFailedException for a lone input
     * @throws TransactionCanceledException for several conditional inputs, e.g. when a condition fails
     * @throws AsyncAwsException if a request to DynamoDB fails otherwise
     */
    public function delete(string $class, DeleteInput ...$inputs): void
    {
        $definition = $this->definitionRegistry->getByEntityClass($class);

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
            $this->transactWrite(new TransactWriteInput([$class => $inputs]));
        } else {
            $this->batchWriteItem($definition, ...$inputs);
        }
    }

    /**
     * Write entities to different tables as one transaction.
     * Once the transaction succeeds the serialized result of every put is applied back onto its entity (see {@see self::put()}).
     * Every update keyed by an entity is backfilled based on {@see UpdateInput::$refresh}:
     * 1. `null` = best effort of keeping the entity up-to-date. Nested paths and actions will not be applied.
     * 2. `true` = entity changes are applied, if necessary a readback is performed.
     * 3. `false` = entity is not updated at all, even if it is an entity and the update could be applied.
     * Readbacks are only necessary if an update writes a nested path or has an action.
     *
     * Transactional writes are limited to 100 operations per batch.
     * A `TransactionConflict` cancellation is retried with backoff; any other cancellation reason is rethrown.
     *
     * @template Entity of AbstractEntity
     *
     * @param TransactWriteInput<Entity> $input
     *
     * @throws UnknownEntityDefinitionException
     * @throws DALException if an entity, an update, a key or a condition does not serialize, an update has nothing to write, or a stored item does not deserialize
     * @throws TransactionCanceledException e.g. when a condition fails or an updated item does not exist; a conflict is retried first
     * @throws AsyncAwsException if a request to DynamoDB fails otherwise
     */
    public function transactWrite(TransactWriteInput $input): void
    {
        /** @var list<array{AbstractEntity, EntityDefinition, array<string, mixed>, NormalizerOperation}> $applies */
        $applies = [];
        /** @var list<AbstractEntity> $refreshes */
        $refreshes = [];
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
                    [$normalized, $update] = $this->expressionCompiler->compileUpdate($definition, $operation->update);
                    $condition = $this->compileUpdateCondition($definition, $operation);

                    $writeRequests[] = new TransactWriteItem(['Update' => [
                        'TableName' => $definition->getTable(),
                        'Key' => $this->serializer->serializeKey($definition, $operation->key),
                        ...$update->getExpression('update'),
                        ...$condition->getExpression('condition'),
                        ...$update->getExpressionAttributes($condition),
                    ]]);

                    if ($operation->refresh !== false && $operation->key instanceof AbstractEntity) {
                        if ($operation->refresh === true && !$normalized->onlySetsWholeFields($definition)) {
                            $refreshes[] = $operation->key;
                        } else {
                            $applies[] = [$operation->key, $definition, $normalized->fields, NormalizerOperation::Update];
                        }
                    }
                }

                if ($operation instanceof PutInput) {
                    $result = $this->serializer->serialize($definition, $operation->entity, NormalizerOperation::Put);
                    $expression = $this->compileExpression($definition, $operation->conditionExpression);

                    $writeRequests[] = new TransactWriteItem(['Put' => [
                        'TableName' => $definition->getTable(),
                        ...$result->getPutExpression(),
                        ...$expression->getExpression('condition'),
                        ...$expression->getExpressionAttributes(),
                    ]]);

                    $applies[] = [$operation->entity, $definition, $result->getNormalizedFields(), $result->getOperation()];
                }
            }
        }

        foreach (array_chunk($writeRequests, self::TRANSACT_WRITE_LIMIT) as $chunk) {
            $this->transactWriteChunkWithConflictRetry($chunk);
        }

        foreach ($applies as [$entity, $definition, $fields, $normalizedFor]) {
            $this->applyFields($entity, $definition, $fields, $normalizedFor);
        }

        // Strongly consistent, so the read-back is guaranteed to observe the write just made
        $this->reader->refresh(new RefreshInput($refreshes, consistentRead: true));
    }

    /**
     * @param array<string, mixed> $fields - normalized, as they were written; a path into an attribute is skipped
     * @param NormalizerOperation $operation - the write they were normalized for
     */
    private function applyFields(AbstractEntity $entity, EntityDefinition $definition, array $fields, NormalizerOperation $operation): void
    {
        $fields = array_filter(
            $fields,
            static fn (string $name): bool => $definition->getFieldDefinition($name) !== null,
            \ARRAY_FILTER_USE_KEY,
        );

        $entity->setVars($this->serializer->denormalize($definition, $fields, $operation));
    }

    /**
     * @param TransactWriteItem[] $chunk
     *
     * @throws TransactionCanceledException unless for a conflict with attempts left
     * @throws AsyncAwsException if a request to DynamoDB fails otherwise
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
     *
     * @throws DALException if an entity or a key does not serialize
     * @throws AsyncAwsException if a request to DynamoDB fails
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
                $result = $this->serializer->serialize($definition, $input->entity, NormalizerOperation::Put);

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
            $this->applyFields($entity, $definition, $result->getNormalizedFields(), $result->getOperation());
        }
    }

    /**
     * @param DeleteInput<AbstractEntity>|PutInput<AbstractEntity>|UpdateInput<AbstractEntity> ...$inputs
     */
    private function hasConditionExpressions(DeleteInput|PutInput|UpdateInput ...$inputs): bool
    {
        return array_any($inputs, static fn ($input): bool => (bool) $input->conditionExpression);
    }

    /**
     * @throws DALException
     */
    private function compileExpression(EntityDefinition $definition, ?FilterInterface $expression): ExpressionCompiledResult
    {
        if ($expression) {
            return $this->expressionCompiler->compileFilter($definition, $expression);
        }

        return new ExpressionCompiledResult();
    }

    /**
     * The update's condition, joined with a check that the item exists.
     * `UpdateItem` otherwise creates a missing item from just its key and the updated fields.
     *
     * @param UpdateInput<AbstractEntity> $input
     *
     * @throws DALException
     */
    private function compileUpdateCondition(EntityDefinition $definition, UpdateInput $input): ExpressionCompiledResult
    {
        $exists = Filter::exists($definition->getKeySchema()->hashKey);

        return $this->compileExpression(
            $definition,
            $input->conditionExpression ? Filter::and($exists, $input->conditionExpression) : $exists,
        );
    }
}
