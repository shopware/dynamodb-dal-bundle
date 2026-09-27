<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Client\Write;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Client\Input\BatchWriteInput;
use Shopware\DynamodbDalBundle\Client\Input\DeleteInput;
use Shopware\DynamodbDalBundle\Client\Input\PutInput;
use Shopware\DynamodbDalBundle\Client\Input\Refresh;
use Shopware\DynamodbDalBundle\Client\Input\TransactWriteInput;
use Shopware\DynamodbDalBundle\Client\Input\UpdateInput;
use Shopware\DynamodbDalBundle\Client\Key;
use Shopware\DynamodbDalBundle\Exception\ConditionEmptyException;
use Shopware\DynamodbDalBundle\Exception\DALException;
use Shopware\DynamodbDalBundle\Exception\DuplicateKeyException;
use Shopware\DynamodbDalBundle\Exception\FieldMissingSerializedValueException;
use Shopware\DynamodbDalBundle\Exception\UnknownEntityDefinitionException;
use Shopware\DynamodbDalBundle\Exception\UpdateDuplicatePathException;
use Shopware\DynamodbDalBundle\Exception\UpdateEmptyException;
use Shopware\DynamodbDalBundle\Expression\Contract\FilterInterface;
use Shopware\DynamodbDalBundle\Expression\ExpressionCompiledResult;
use Shopware\DynamodbDalBundle\Expression\Filter;
use Shopware\DynamodbDalBundle\Expression\FilterCompiler;
use Shopware\DynamodbDalBundle\Expression\Update\UpdateExpression;
use Shopware\DynamodbDalBundle\Expression\UpdateCompiler;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\EntityDefinitionRegistry;
use Shopware\DynamodbDalBundle\Serializer\NormalizerOperation;
use Shopware\DynamodbDalBundle\Serializer\SerializedKeyResult;
use Shopware\DynamodbDalBundle\Serializer\Serializer;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;

/**
 * Turns write inputs into the requests DynamoDB takes: a put, update or delete into one it takes alone or in a
 * transaction, and puts and deletes into a {@see BatchWrite}.
 *
 * @internal
 *
 * @phpstan-type PutRequest array{
 *   TableName: string,
 *   Item: array<string, AttributeValue>,
 *   ConditionExpression?: string,
 *   ExpressionAttributeNames?: array<string, string>,
 *   ExpressionAttributeValues?: array<string, AttributeValue>,
 * }
 * @phpstan-type UpdateRequest array{
 *   TableName: string,
 *   Key: array<string, AttributeValue>,
 *   UpdateExpression?: string,
 *   ConditionExpression?: string,
 *   ExpressionAttributeNames?: array<string, string>,
 *   ExpressionAttributeValues?: array<string, AttributeValue>,
 * }
 * @phpstan-type DeleteRequest array{
 *   TableName: string,
 *   Key: array<string, AttributeValue>,
 *   ConditionExpression?: string,
 *   ExpressionAttributeNames?: array<string, string>,
 *   ExpressionAttributeValues?: array<string, AttributeValue>,
 * }
 */
final readonly class WriteRequestFactory
{
    /**
     * @internal
     */
    public function __construct(
        private Serializer $serializer,
        private FilterCompiler $filterCompiler,
        private UpdateCompiler $updateCompiler,
        private EntityDefinitionRegistry $definitionRegistry,
    ) {
    }

    /**
     * @param PutInput<AbstractEntity>|UpdateInput<AbstractEntity>|DeleteInput<AbstractEntity> $input
     *
     * @throws UnknownEntityDefinitionException
     * @throws ConditionEmptyException
     * @throws UpdateEmptyException if an update has nothing to write
     * @throws UpdateDuplicatePathException if an update gives a path two values
     * @throws FieldMissingSerializedValueException if an update removes a field that is not nullable
     * @throws DALException if an entity, an update, a key or a condition does not serialize
     *
     * @return PreparedWrite<PutRequest|UpdateRequest|DeleteRequest>
     */
    public function prepare(PutInput|UpdateInput|DeleteInput $input): PreparedWrite
    {
        return match (true) {
            $input instanceof PutInput => $this->put($input),
            $input instanceof UpdateInput => $this->update($input),
            $input instanceof DeleteInput => $this->delete($input),
        };
    }

    /**
     * The writes of a transaction, in the order its operations were added.
     *
     * DynamoDB refuses a transaction that writes an item twice. Past 100 operations, whether two of them land in one
     * transaction depends on where they stand, so a key named twice is refused before any of it is sent.
     *
     * @throws UnknownEntityDefinitionException
     * @throws DuplicateKeyException if two of its operations name the same key
     * @throws ConditionEmptyException
     * @throws UpdateEmptyException if an update has nothing to write
     * @throws UpdateDuplicatePathException if an update gives a path two values
     * @throws FieldMissingSerializedValueException if an update removes a field that is not nullable
     * @throws DALException if an entity, an update, a key or a condition does not serialize
     *
     * @return list<PreparedWrite<PutRequest|UpdateRequest|DeleteRequest>>
     */
    public function transaction(TransactWriteInput $input): array
    {
        $writes = [];
        foreach ($input->operations as $operation) {
            $write = $this->prepare($operation);
            if (isset($writes[$write->key->hash])) {
                throw new DuplicateKeyException($write->key->definition, $operation instanceof PutInput ? $operation->entity : $operation->key);
            }

            $writes[$write->key->hash] = $write;
        }

        return array_values($writes);
    }

    /**
     * The entity takes the fields the put wrote back, as they were normalized for it.
     *
     * @param PutInput<AbstractEntity> $input
     *
     * @throws UnknownEntityDefinitionException
     * @throws ConditionEmptyException
     * @throws DALException if the entity or the condition does not serialize
     *
     * @return PreparedWrite<PutRequest>
     */
    public function put(PutInput $input): PreparedWrite
    {
        $definition = $this->definitionRegistry->getByEntityClass($input->class);

        $result = $this->serializer->serialize($definition, $input->entity, NormalizerOperation::Put);
        $condition = $this->condition($definition, $input->condition);

        return new PreparedWrite(
            'Put',
            SerializedKeyResult::fromItem($definition, $result->getFields()),
            [
                'TableName' => $definition->getTable(),
                ...$result->getPutExpression(),
                ...$condition->getExpression('condition'),
                ...$condition->getExpressionAttributes(),
            ],
            WriteBack::fields($input->entity, $definition, $result->getNormalizedFields(), $result->getOperation()),
        );
    }

    /**
     * Conditioned on its item existing, so a missing key fails instead of creating a partial item. An entity given as
     * key is brought up to date as {@see UpdateInput::$refresh} says: it takes the fields the update wrote, or is read
     * back with {@see Refresh::Full} where the update writes a nested path or has an action.
     *
     * @param UpdateInput<AbstractEntity> $input
     *
     * @throws UnknownEntityDefinitionException
     * @throws ConditionEmptyException
     * @throws UpdateEmptyException if the update has nothing to write
     * @throws UpdateDuplicatePathException if the update gives a path two values
     * @throws FieldMissingSerializedValueException if the update removes a field that is not nullable
     * @throws DALException if the update, the key or the condition does not serialize
     *
     * @return PreparedWrite<UpdateRequest>
     */
    public function update(UpdateInput $input): PreparedWrite
    {
        $definition = $this->definitionRegistry->getByEntityClass($input->class);

        [$normalized, $update] = $this->updateCompiler->update($definition, $input->update);
        $condition = $this->updateCondition($definition, $input->condition);
        $key = $this->serializer->serializeKey($definition, $input->key);

        return new PreparedWrite(
            'Update',
            $key,
            [
                'TableName' => $definition->getTable(),
                'Key' => $key->fields,
                ...$update->getExpression('update'),
                ...$condition->getExpression('condition'),
                ...$update->getExpressionAttributes($condition),
            ],
            $this->updateWriteBack($definition, $input, $normalized),
        );
    }

    /**
     * @param DeleteInput<AbstractEntity> $input
     *
     * @throws UnknownEntityDefinitionException
     * @throws ConditionEmptyException
     * @throws DALException if the key or the condition does not serialize
     *
     * @return PreparedWrite<DeleteRequest>
     */
    public function delete(DeleteInput $input): PreparedWrite
    {
        $definition = $this->definitionRegistry->getByEntityClass($input->class);

        $condition = $this->condition($definition, $input->condition);
        $key = $this->serializer->serializeKey($definition, $input->key);

        return new PreparedWrite(
            'Delete',
            $key,
            [
                'TableName' => $definition->getTable(),
                'Key' => $key->fields,
                ...$condition->getExpression('condition'),
                ...$condition->getExpressionAttributes(),
            ],
        );
    }

    /**
     * @throws UnknownEntityDefinitionException
     * @throws DuplicateKeyException if two of its puts and deletes name the same key
     * @throws DALException if an entity or a key does not serialize
     */
    public function batch(BatchWriteInput $input): BatchWrite
    {
        $batch = new BatchWrite();

        foreach ($input->puts as $entity) {
            $definition = $this->definitionRegistry->getByEntityClass($entity::class);

            $batch->put($definition, $entity, $this->serializer->serialize($definition, $entity, NormalizerOperation::Put));
        }

        foreach ($input->deletes as $key) {
            $definition = $this->definitionRegistry->getByEntityClass($key instanceof Key ? $key->class : $key::class);

            $batch->delete($key, $this->serializer->serializeKey($definition, $key));
        }

        return $batch;
    }

    /**
     * @param UpdateInput<AbstractEntity> $input
     */
    private function updateWriteBack(EntityDefinition $definition, UpdateInput $input, UpdateExpression $normalized): ?WriteBack
    {
        if ($input->refresh === Refresh::None || !$input->key instanceof AbstractEntity) {
            return null;
        }

        if ($input->refresh === Refresh::Full && !$normalized->onlySetsWholeFields($definition)) {
            return WriteBack::readBack($input->key, $definition);
        }

        return WriteBack::fields($input->key, $definition, $normalized->fields, NormalizerOperation::Update);
    }

    /**
     * @throws ConditionEmptyException
     * @throws DALException
     */
    private function condition(EntityDefinition $definition, ?FilterInterface $condition): ExpressionCompiledResult
    {
        if ($condition === null) {
            return new ExpressionCompiledResult();
        }

        return $this->filterCompiler->condition($definition, $condition);
    }

    /**
     * The update's condition, joined with a check that the item exists.
     * `UpdateItem` otherwise creates a missing item from just its key and the updated fields.
     *
     * @throws ConditionEmptyException
     * @throws DALException
     */
    private function updateCondition(EntityDefinition $definition, ?FilterInterface $condition): ExpressionCompiledResult
    {
        $exists = Filter::exists($definition->getKeySchema()->hashKey);

        return $condition !== null
            ? $this->filterCompiler->condition($definition, $exists, $condition)
            : $this->filterCompiler->condition($definition, $exists);
    }
}
