<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Serializer;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Client\Key;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\FieldDefinition;
use Shopware\DynamodbDalBundle\Definition\FieldPath;
use Shopware\DynamodbDalBundle\Exception\DALException;
use Shopware\DynamodbDalBundle\Exception\FieldDeserializationException;
use Shopware\DynamodbDalBundle\Exception\FieldMissingDeserializedValueException;
use Shopware\DynamodbDalBundle\Exception\FieldMissingSerializedValueException;
use Shopware\DynamodbDalBundle\Exception\FieldSerializationException;
use Shopware\DynamodbDalBundle\Exception\UnknownFieldException;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;

/**
 * Serializes and deserializes items based on their definition and field serializers.
 *
 * @internal
 */
class Serializer
{
    /**
     * Deserializes a whole item only, do not pass partial data.
     *
     * @template Entity of AbstractEntity
     *
     * @param EntityDefinition<Entity> $definition
     * @param array<string, AttributeValue> $output
     * @param Entity|null $entity - If provided backfills to this entity instead of creating a new one
     *
     * @throws DALException if deserialization of any field fails or a required field value is missing after deserialization and denormalization
     *
     * @return Entity
     */
    public function deserialize(EntityDefinition $definition, array $output, ?AbstractEntity $entity = null): ?AbstractEntity
    {
        // Any existing item should have at least its primary key fields present.
        // If array is empty, like `GetItem` returns for a key that matches no item, we can assume that the item does not exist and return null.
        if (!$output) {
            return null;
        }

        $fields = $this->deserializeFields($definition, [
            ...array_fill_keys($definition->getFieldNames(), null),
            ...$output,
        ], NormalizerOperation::Read);

        foreach ($definition->getFieldDefinitions() as $name => $fieldDefinition) {
            if (
                !$fieldDefinition->allowsNull()
                && !$fieldDefinition->hasDefaultValue()
                && (!\array_key_exists($name, $fields) || $fields[$name] === null)
            ) {
                throw new FieldMissingDeserializedValueException($fieldDefinition);
            }
        }

        return ($entity ?? $definition->createInstance())->setVars($fields);
    }

    /**
     * Deserializes the provided fields, a whole item or only some of them, such as a key.
     *
     * @template Entity of AbstractEntity
     *
     * @param EntityDefinition<Entity> $definition
     * @param array<string, AttributeValue|null> $output
     * @param NormalizerOperation $operation - what the fields are, as the normalizer is told
     *
     * @throws DALException
     *
     * @return array<string, mixed>
     */
    public function deserializeFields(EntityDefinition $definition, array $output, NormalizerOperation $operation): array
    {
        $fields = [];
        foreach ($output as $name => $attributeValue) {
            $fieldDefinition = $definition->getFieldDefinition($name);
            if (!$fieldDefinition) {
                continue;
            }

            if ($attributeValue === null) {
                if ($fieldDefinition->allowsNull()) {
                    $fields[$name] = null;

                    continue;
                }

                if ($fieldDefinition->hasDefaultValue()) {
                    $fields[$name] = $fieldDefinition->getDefaultValue();

                    continue;
                }

                // Left for the normalizer to fill in; deserialize() fails on it if it stays null
                $fields[$name] = null;

                continue;
            }

            $fields[$name] = $this->deserializeValue($fieldDefinition, $attributeValue);
        }

        return $this->denormalize($definition, $fields, $operation);
    }

    /**
     * Deserializes one value of a field with the field's serializer, without the normalizer: a whole field, or a value
     * within one, such as a map entry by the map's value definition.
     *
     * @param ?string $path - where the value is within the item, such as `settings.theme`, for the failure to name
     *
     * @throws DALException if the serializer refuses the value, as it threw it or as a {@see FieldDeserializationException}
     */
    public function deserializeValue(FieldDefinition $definition, AttributeValue $value, ?string $path = null): mixed
    {
        try {
            return $definition->getSerializer()->deserialize($definition, $value);
        } catch (\Throwable $e) {
            if ($e instanceof DALException) {
                throw $e;
            }

            throw new FieldDeserializationException($definition, $e, $path);
        }
    }

    /**
     * Serializes a whole item or only specified fields.
     *
     * @template Entity of AbstractEntity
     *
     * @param EntityDefinition<Entity> $definition
     * @param array<string, mixed> $fields
     * @param NormalizerOperation $operation - what the fields are for, as the normalizer is told
     *
     * @throws DALException if a provided field does not exist in the definition or a required field value is missing
     */
    public function serialize(EntityDefinition $definition, AbstractEntity|array $fields, NormalizerOperation $operation): SerializedResult
    {
        if ($fields instanceof AbstractEntity) {
            $defaultFields = array_fill_keys($definition->getFieldNames(), null);
            $fields = array_filter(
                [...$defaultFields, ...$fields->getVars()],
                static fn (string $name): bool => \array_key_exists($name, $defaultFields),
                \ARRAY_FILTER_USE_KEY,
            );
        }

        $fields = $this->normalize($definition, $fields, $operation);

        $result = [];
        foreach ($fields as $name => $value) {
            // A field name outside the definition is a typo, not a value to skip silently.
            $path = FieldPath::parse($definition, $name);

            // DynamoDB has no null attribute, so an unset value is absent from a put.
            if ($value === null && $path->definition->allowsNull()) {
                $result[$name] = new SerializedFieldResult($path, null);

                continue;
            }

            // The normalizer ran before this and did not fill it, so the value is genuinely unset. A default is no
            // fallback: an entity holds it already unless it was unset() or its normalizer removed it, and a key
            // missing a part of it must not address another item.
            if ($value === null) {
                throw new FieldMissingSerializedValueException($path->definition);
            }

            try {
                $serialized = $path->definition->getSerializer()->serialize($path->definition, $value);
            } catch (\Throwable $e) {
                if ($e instanceof DALException) {
                    throw $e;
                }

                throw new FieldSerializationException($path->definition, $e, $path->path);
            }

            $result[$name] = new SerializedFieldResult($path, $serialized);
        }

        return new SerializedResult($result, $fields, $operation);
    }

    /**
     * @template Entity of AbstractEntity
     *
     * @param EntityDefinition<Entity> $definition
     * @param Entity|Key<Entity> $key
     *
     * @throws DALException if a provided field does not exist in the definition or a required field value is missing
     *
     * @return SerializedKeyResult<Entity>
     */
    public function serializeKey(EntityDefinition $definition, AbstractEntity|Key $key): SerializedKeyResult
    {
        if ($key instanceof AbstractEntity) {
            $keySchema = $definition->getKeySchema();
            $vars = $key->getVars();
            $key = new Key(
                $definition->getClass(),
                $vars[$keySchema->hashKey] ?? null,
                $keySchema->rangeKey !== null ? ($vars[$keySchema->rangeKey] ?? null) : null,
            );
        }

        $keySchema = $definition->getKeySchema();
        $fields = [$keySchema->hashKey => $key->hashValue];
        if ($keySchema->rangeKey !== null) {
            $fields[$keySchema->rangeKey] = $key->rangeValue;
        }

        $result = $this->serialize($definition, $fields, NormalizerOperation::Key)->getFields();

        // A DynamoDB key must carry every key attribute; a key field that serialized to nothing is a bug.
        foreach (array_keys($fields) as $name) {
            if (!isset($result[$name])) {
                $fieldDefinition = $definition->getFieldDefinition($name);
                if ($fieldDefinition === null) {
                    throw new UnknownFieldException($definition, $name);
                }

                throw new FieldMissingSerializedValueException($fieldDefinition);
            }
        }

        return SerializedKeyResult::fromItem($definition, $result);
    }

    /**
     * Normalizes a whole item or only specified fields.
     *
     * @param array<string, mixed> $fields - `['fieldName' => fieldValue]`
     *
     * @return array<string, mixed>
     */
    public function normalize(EntityDefinition $definition, array $fields, NormalizerOperation $operation): array
    {
        $normalizer = $definition->getNormalizer();
        if (!$normalizer) {
            return $fields;
        }

        $context = NormalizerContext::fromFields($operation, $fields);
        $normalizer->normalize($context);

        return $context->getFields();
    }

    /**
     * Denormalizes whole item fields or only provided partial fields.
     *
     * @param array<string, mixed> $fields - `['fieldName' => fieldValue]`
     *
     * @return array<string, mixed>
     */
    public function denormalize(EntityDefinition $definition, array $fields, NormalizerOperation $operation): array
    {
        $normalizer = $definition->getNormalizer();
        if (!$normalizer) {
            return $fields;
        }

        $context = NormalizerContext::fromFields($operation, $fields);
        $normalizer->denormalize($context);

        return $context->getFields();
    }
}
