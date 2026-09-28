<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Serializer;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Client\Key;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\FieldPath;
use Shopware\DynamodbDalBundle\Exception\DALException;
use Shopware\DynamodbDalBundle\Exception\DenormalizationException;
use Shopware\DynamodbDalBundle\Exception\FieldDeserializationException;
use Shopware\DynamodbDalBundle\Exception\FieldMissingDeserializedValueException;
use Shopware\DynamodbDalBundle\Exception\FieldMissingSerializedValueException;
use Shopware\DynamodbDalBundle\Exception\FieldSerializationException;
use Shopware\DynamodbDalBundle\Exception\NormalizationException;
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

        return $this->assign($definition, $entity ?? $definition->createInstance(), $fields);
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

            try {
                $fields[$name] = $fieldDefinition->getSerializer()->deserialize($fieldDefinition, $attributeValue);
            } catch (\Throwable $e) {
                if ($e instanceof DALException) {
                    throw $e;
                }

                throw new FieldDeserializationException($fieldDefinition, $e);
            }
        }

        return $this->denormalize($definition, $fields, $operation);
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
     * @throws DALException if the normalizer fails, as it threw it or as a {@see NormalizationException}
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

        try {
            $normalizer->normalize($context);
        } catch (\Throwable $e) {
            if ($e instanceof DALException) {
                throw $e;
            }

            throw new NormalizationException($definition, $e);
        }

        return $context->getFields();
    }

    /**
     * Denormalizes whole item fields or only provided partial fields.
     *
     * @param array<string, mixed> $fields - `['fieldName' => fieldValue]`
     *
     * @throws DALException if the normalizer fails, as it threw it or as a {@see DenormalizationException}
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

        try {
            $normalizer->denormalize($context);
        } catch (\Throwable $e) {
            if ($e instanceof DALException) {
                throw $e;
            }

            throw new DenormalizationException($definition, $e);
        }

        return $context->getFields();
    }

    /**
     * Assigns denormalized fields to the entity's properties, one by one.
     *
     * @template Entity of AbstractEntity
     *
     * @param EntityDefinition<Entity> $definition
     * @param Entity $entity
     * @param array<string, mixed> $fields - `['fieldName' => fieldValue]`
     *
     * @throws DALException if a property refuses its value, such as with a `\TypeError` or from a `set` hook
     *
     * @return Entity
     */
    public function assign(EntityDefinition $definition, AbstractEntity $entity, array $fields): AbstractEntity
    {
        foreach ($fields as $name => $value) {
            try {
                $entity->setVars([$name => $value]);
            } catch (\Throwable $e) {
                if ($e instanceof DALException) {
                    throw $e;
                }

                $fieldDefinition = $definition->getFieldDefinition($name);

                // Only the normalizer can have added a value for a property that is no field
                throw $fieldDefinition !== null ? new FieldDeserializationException($fieldDefinition, $e) : new DenormalizationException($definition, $e);
            }
        }

        return $entity;
    }
}
