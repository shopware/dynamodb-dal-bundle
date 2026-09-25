<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Client;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Client\Input\BatchWriteInput;
use Shopware\DynamodbDalBundle\Client\Input\DeleteInput;
use Shopware\DynamodbDalBundle\Client\Input\PutInput;
use Shopware\DynamodbDalBundle\Client\Input\Refresh;
use Shopware\DynamodbDalBundle\Client\Input\RefreshInput;
use Shopware\DynamodbDalBundle\Client\Input\TransactWriteInput;
use Shopware\DynamodbDalBundle\Client\Input\UpdateInput;
use Shopware\DynamodbDalBundle\Exception\ConditionEmptyException;
use Shopware\DynamodbDalBundle\Exception\DALException;
use Shopware\DynamodbDalBundle\Exception\FieldMissingSerializedValueException;
use Shopware\DynamodbDalBundle\Exception\UnknownEntityDefinitionException;
use Shopware\DynamodbDalBundle\Exception\UpdateDuplicatePathException;
use Shopware\DynamodbDalBundle\Exception\UpdateEmptyException;
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
 * The write side behind {@see Client}: single-item writes, batches and transactions.
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
     * A cancellation is retried only if every reason is one of these, and at least one is a `TransactionConflict`.
     * `None` marks an operation that did not cause the cancellation, so it is not retried on its own.
     *
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
     * Writes the entity with `PutItem`, then applies the serialized result back onto it so normalization-generated
     * values (e.g. a `Uuid::v7()` key or `createdAt`) are reflected on the entity.
     *
     * @template Entity of AbstractEntity
     *
     * @param PutInput<Entity> $input
     *
     * @throws UnknownEntityDefinitionException
     * @throws ConditionEmptyException
     * @throws DALException if the entity or the condition does not serialize
     * @throws ConditionalCheckFailedException
     * @throws AsyncAwsException if a request to DynamoDB fails otherwise
     */
    public function put(PutInput $input): void
    {
        $definition = $this->definitionRegistry->getByEntityClass($input->class);

        $result = $this->serializer->serialize($definition, $input->entity, NormalizerOperation::Put);
        $condition = $this->compileCondition($definition, $input->condition);

        $this->client->putItem([
            'TableName' => $definition->getTable(),
            ...$result->getPutExpression(),
            ...$condition->getExpression('condition'),
            ...$condition->getExpressionAttributes(),
        ])->resolve();

        $this->applyFields($input->entity, $definition, $result->getNormalizedFields(), $result->getOperation());
    }

    /**
     * Updates the item with `UpdateItem`. Unlike {@see self::put()}, an update writes only the fields it names.
     * Every update is conditioned on its item existing, so a missing key fails instead of creating a partial item.
     * An entity given as key takes the stored item back, unless {@see UpdateInput::$refresh} is {@see Refresh::None}.
     *
     * @template Entity of AbstractEntity
     *
     * @param UpdateInput<Entity> $input
     *
     * @throws UnknownEntityDefinitionException
     * @throws ConditionEmptyException
     * @throws UpdateEmptyException if the update has nothing to write
     * @throws UpdateDuplicatePathException if the update gives a path two values
     * @throws FieldMissingSerializedValueException if the update removes a field that is not nullable
     * @throws DALException if the update, the key or the condition does not serialize, or the stored item does not deserialize
     * @throws ConditionalCheckFailedException when the item does not exist or the condition fails
     * @throws AsyncAwsException if a request to DynamoDB fails otherwise
     */
    public function update(UpdateInput $input): void
    {
        $definition = $this->definitionRegistry->getByEntityClass($input->class);

        [, $update] = $this->expressionCompiler->compileUpdate($definition, $input->update);
        $condition = $this->compileUpdateCondition($definition, $input);
        $entity = $input->refresh !== Refresh::None && $input->key instanceof AbstractEntity ? $input->key : null;

        $output = $this->client->updateItem([
            'TableName' => $definition->getTable(),
            'Key' => $this->serializer->serializeKey($definition, $input->key),
            ...($entity ? ['ReturnValues' => ReturnValue::ALL_NEW] : []),
            ...$update->getExpression('update'),
            ...$condition->getExpression('condition'),
            ...$update->getExpressionAttributes($condition),
        ]);

        $output->resolve();

        if ($entity) {
            $this->serializer->deserialize($definition, $output->getAttributes(), $entity);
        }
    }

    /**
     * Deletes the item with `DeleteItem`. Deleting an item that does not exist is not an error.
     *
     * @template Entity of AbstractEntity
     *
     * @param DeleteInput<Entity> $input
     *
     * @throws UnknownEntityDefinitionException
     * @throws ConditionEmptyException
     * @throws DALException if the key or the condition does not serialize
     * @throws ConditionalCheckFailedException
     * @throws AsyncAwsException if a request to DynamoDB fails otherwise
     */
    public function delete(DeleteInput $input): void
    {
        $definition = $this->definitionRegistry->getByEntityClass($input->class);

        $condition = $this->compileCondition($definition, $input->condition);

        $this->client->deleteItem([
            'TableName' => $definition->getTable(),
            'Key' => $this->serializer->serializeKey($definition, $input->key),
            ...$condition->getExpression('condition'),
            ...$condition->getExpressionAttributes(),
        ])->resolve();
    }

    /**
     * Writes puts and deletes across tables with `BatchWriteItem`, 25 per request, and resubmits whatever DynamoDB
     * leaves unprocessed. Not atomic: a failed request leaves the requests before it written. Once every request
     * succeeded, each put is applied back onto its entity, as for {@see self::put()}.
     *
     * @throws UnknownEntityDefinitionException
     * @throws DALException if an entity or a key does not serialize
     * @throws AsyncAwsException if a request to DynamoDB fails
     */
    public function batchWrite(BatchWriteInput $input): void
    {
        /** @var list<array{string, WriteRequest}> $writeRequests - physical table and request */
        $writeRequests = [];
        /** @var list<array{AbstractEntity, EntityDefinition, SerializedResult}> $puts */
        $puts = [];

        foreach ($input->puts as $entity) {
            $definition = $this->definitionRegistry->getByEntityClass($entity::class);

            $result = $this->serializer->serialize($definition, $entity, NormalizerOperation::Put);
            $writeRequests[] = [$definition->getTable(), new WriteRequest(['PutRequest' => $result->getPutExpression()])];
            $puts[] = [$entity, $definition, $result];
        }

        foreach ($input->deletes as $key) {
            $definition = $this->definitionRegistry->getByEntityClass($key instanceof Key ? $key->class : $key::class);

            $writeRequests[] = [$definition->getTable(), new WriteRequest([
                'DeleteRequest' => ['Key' => $this->serializer->serializeKey($definition, $key)],
            ])];
        }

        // The limit of 25 counts requests across all tables of one call.
        foreach (array_chunk($writeRequests, self::BATCH_WRITE_LIMIT) as $chunk) {
            $requestItems = [];
            foreach ($chunk as [$table, $request]) {
                $requestItems[$table][] = $request;
            }

            do {
                $output = $this->client->batchWriteItem(['RequestItems' => $requestItems]);

                // Keyed by table like the request, so the leftovers can be sent again as they are; empty is done
                $requestItems = $output->getUnprocessedItems();
            } while ($requestItems !== []);
        }

        foreach ($puts as [$entity, $definition, $result]) {
            $this->applyFields($entity, $definition, $result->getNormalizedFields(), $result->getOperation());
        }
    }

    /**
     * Writes puts, updates and deletes across tables with `TransactWriteItems`, in the order the operations were added.
     * Past 100 operations, the input is split into several transactions, each atomic on its own. A `TransactionConflict`
     * cancellation is retried with backoff; any other cancellation is rethrown.
     *
     * Once the transactions succeeded, the serialized result of every put is applied back onto its entity, as for
     * {@see self::put()}, and every update keyed by an entity brings it up to date as its {@see UpdateInput::$refresh}
     * says. Only {@see Refresh::Full} reads an item back, where an update writes a nested path or has an action.
     *
     * @throws UnknownEntityDefinitionException
     * @throws ConditionEmptyException
     * @throws UpdateEmptyException if an update has nothing to write
     * @throws UpdateDuplicatePathException if an update gives a path two values
     * @throws FieldMissingSerializedValueException if an update removes a field that is not nullable
     * @throws DALException if an entity, an update, a key or a condition does not serialize, or a stored item does not deserialize
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

        foreach ($input->operations as $operation) {
            $definition = $this->definitionRegistry->getByEntityClass($operation->class);

            if ($operation instanceof DeleteInput) {
                $condition = $this->compileCondition($definition, $operation->condition);

                $writeRequests[] = new TransactWriteItem(['Delete' => [
                    'TableName' => $definition->getTable(),
                    'Key' => $this->serializer->serializeKey($definition, $operation->key),
                    ...$condition->getExpression('condition'),
                    ...$condition->getExpressionAttributes(),
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

                if ($operation->refresh !== Refresh::None && $operation->key instanceof AbstractEntity) {
                    if ($operation->refresh === Refresh::Full && !$normalized->onlySetsWholeFields($definition)) {
                        $refreshes[] = $operation->key;
                    } else {
                        $applies[] = [$operation->key, $definition, $normalized->fields, NormalizerOperation::Update];
                    }
                }
            }

            if ($operation instanceof PutInput) {
                $result = $this->serializer->serialize($definition, $operation->entity, NormalizerOperation::Put);
                $condition = $this->compileCondition($definition, $operation->condition);

                $writeRequests[] = new TransactWriteItem(['Put' => [
                    'TableName' => $definition->getTable(),
                    ...$result->getPutExpression(),
                    ...$condition->getExpression('condition'),
                    ...$condition->getExpressionAttributes(),
                ]]);

                $applies[] = [$operation->entity, $definition, $result->getNormalizedFields(), $result->getOperation()];
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
     * @throws ConditionEmptyException
     * @throws DALException
     */
    private function compileCondition(EntityDefinition $definition, ?FilterInterface $condition): ExpressionCompiledResult
    {
        if ($condition === null) {
            return new ExpressionCompiledResult();
        }

        return $this->expressionCompiler->compileCondition($definition, $condition);
    }

    /**
     * The update's condition, joined with a check that the item exists.
     * `UpdateItem` otherwise creates a missing item from just its key and the updated fields.
     *
     * @param UpdateInput<AbstractEntity> $input
     *
     * @throws ConditionEmptyException
     * @throws DALException
     */
    private function compileUpdateCondition(EntityDefinition $definition, UpdateInput $input): ExpressionCompiledResult
    {
        $exists = Filter::exists($definition->getKeySchema()->hashKey);

        return $input->condition !== null
            ? $this->expressionCompiler->compileCondition($definition, $exists, $input->condition)
            : $this->expressionCompiler->compileCondition($definition, $exists);
    }
}
