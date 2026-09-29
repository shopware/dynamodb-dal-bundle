<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Client\Write;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Client\Input\BatchWriteInput;
use Shopware\DynamodbDalBundle\Client\Input\DeleteInput;
use Shopware\DynamodbDalBundle\Client\Input\PutInput;
use Shopware\DynamodbDalBundle\Client\Input\Refresh;
use Shopware\DynamodbDalBundle\Client\Input\TransactWriteInput;
use Shopware\DynamodbDalBundle\Client\Input\UpdateInput;
use Shopware\DynamodbDalBundle\Client\Input\UpsertInput;
use Shopware\DynamodbDalBundle\Client\Key;
use Shopware\DynamodbDalBundle\Exception\AttributeTypeMismatchException;
use Shopware\DynamodbDalBundle\Exception\ConditionEmptyException;
use Shopware\DynamodbDalBundle\Exception\DALException;
use Shopware\DynamodbDalBundle\Exception\DuplicateKeyException;
use Shopware\DynamodbDalBundle\Exception\FieldMissingSerializedValueException;
use Shopware\DynamodbDalBundle\Exception\UnknownEntityDefinitionException;
use Shopware\DynamodbDalBundle\Exception\UpdateDuplicatePathException;
use Shopware\DynamodbDalBundle\Exception\UpdateEmptyException;
use Shopware\DynamodbDalBundle\Exception\UpsertKeyMismatchException;
use Shopware\DynamodbDalBundle\Expression\Contract\FilterInterface;
use Shopware\DynamodbDalBundle\Expression\ExpressionCompiledResult;
use Shopware\DynamodbDalBundle\Expression\Filter;
use Shopware\DynamodbDalBundle\Expression\FilterCompiler;
use Shopware\DynamodbDalBundle\Expression\Update\UpdateExpression;
use Shopware\DynamodbDalBundle\Expression\UpdateCompiler;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\EntityDefinitionRegistry;
use Shopware\DynamodbDalBundle\Definition\FieldPath;
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
        // `UpdateItem` otherwise creates a missing item from just its key and the updated fields
        $condition = $this->condition($definition, Filter::exists($definition->getKeySchema()->hashKey), $input->condition);
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
     * An upsert as the update of the stored item and the put of the entity, both prepared before either is sent. The
     * update is conditioned on the item existing, as every update is, and the put on its key being free, each on top of
     * the upsert's own condition, so at most one of them is written. With {@see Refresh::None}, the put leaves the entity
     * as it is, as the update does.
     *
     * @param UpsertInput<AbstractEntity> $input
     *
     * @throws UnknownEntityDefinitionException
     * @throws ConditionEmptyException
     * @throws UpdateEmptyException if the update has nothing to write
     * @throws UpdateDuplicatePathException if the update gives a path two values
     * @throws FieldMissingSerializedValueException if the update removes a field that is not nullable, or the entity lacks a required value
     * @throws UpsertKeyMismatchException if the normalizer gives the key other values for the put than for the update
     * @throws DALException if the entity, the update, a path, the key or the condition does not serialize
     *
     * @return array{PreparedWrite<UpdateRequest>, PreparedWrite<PutRequest>} - the update, and the put
     */
    public function upsert(UpsertInput $input): array
    {
        $definition = $this->definitionRegistry->getByEntityClass($input->class);

        // `and()` drops a condition that checks nothing, which the update, compiling it on its own, refuses
        $put = $this->put(new PutInput($input->entity, Filter::and(Filter::notExists($definition->getKeySchema()->hashKey), $input->condition)));

        $update = $this->update(new UpdateInput(
            $input->entity,
            \is_array($input->update) ? $this->entityValuesAt($definition, $input->entity, $put->request['Item'], $input->update) : $input->update,
            $input->condition,
            $input->refresh,
        ));

        // The update's key is normalized as a key and the put's as part of the entity. Where the two differ, the update
        // misses the row the put finds, and every round ends as contention.
        if ($update->key->hash !== $put->key->hash) {
            throw new UpsertKeyMismatchException($definition, $input->entity);
        }

        return [$update, $input->refresh === Refresh::None ? new PreparedWrite($put->type, $put->key, $put->request) : $put];
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
     * The update of each path to the entity's value there. A path the entity holds no value for, such as a map key it
     * lacks or holds as `null`, is removed. A key field is left out, since the key names the item.
     *
     * A whole field takes the entity's own value, which the update's normalizer then sees as it would for any update.
     * A path into a field takes its value from the item the put writes: only the field's serializer, and the normalizer
     * before it, know how the field is stored, such as an object as a map.
     *
     * @param array<string, AttributeValue> $item - the item the put writes
     * @param list<string> $paths
     *
     * @throws AttributeTypeMismatchException if a path descends into an attribute the item does not store as a map or a list
     * @throws DALException if a path does not address a field the entity has, or a value within a field does not deserialize
     */
    private function entityValuesAt(EntityDefinition $definition, AbstractEntity $entity, array $item, array $paths): UpdateExpression
    {
        $keyFields = $definition->getKeySchema()->getFields();
        $vars = $entity->getVars();

        $fields = [];
        foreach ($paths as $path) {
            if (\in_array($path, $keyFields, true)) {
                continue;
            }

            $fieldPath = FieldPath::parse($definition, $path);
            if (!$fieldPath->isNested()) {
                $fields[$path] = $vars[$path] ?? null;

                continue;
            }

            $stored = $fieldPath->traverse($item);
            $fields[$path] = $stored !== null ? $this->serializer->deserializeValue($fieldPath->definition, $stored, $path) : null;
        }

        return new UpdateExpression($fields);
    }

    /**
     * The conditions given, joined with `AND`. Each is compiled on its own, so one that checks nothing is refused rather
     * than hidden behind another.
     *
     * @throws ConditionEmptyException
     * @throws DALException
     */
    private function condition(EntityDefinition $definition, ?FilterInterface ...$conditions): ExpressionCompiledResult
    {
        $conditions = array_values(array_filter($conditions, static fn (?FilterInterface $condition): bool => $condition !== null));
        if ($conditions === []) {
            return new ExpressionCompiledResult();
        }

        return $this->filterCompiler->condition($definition, ...$conditions);
    }
}
