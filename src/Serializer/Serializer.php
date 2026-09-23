<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Serializer;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Client\Cursor\Cursor;
use Shopware\DynamodbDalBundle\Client\Index;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\FieldPath;
use Shopware\DynamodbDalBundle\Exception\DALException;
use Shopware\DynamodbDalBundle\Exception\SerializerException;
use AsyncAws\DynamoDb\Result\GetItemOutput;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;

/**
 * Serializes and deserializes items based on their definition and field serializers.
 */
class Serializer
{
    /**
     * Deserializes a whole item only, do not pass partial data.
     *
     * @template Entity of AbstractEntity
     *
     * @param EntityDefinition<Entity> $definition
     * @param GetItemOutput|array<string, AttributeValue> $output
     *
     * @throws SerializerException if deserialization of any field fails or a required field value is missing after deserialization and denormalization
     *
     * @return Entity
     */
    public function deserialize(EntityDefinition $definition, GetItemOutput|array $output): ?AbstractEntity
    {
        if ($output instanceof GetItemOutput) {
            $output = $output->getItem();
        }

        // Any existing item should have at least its primary key fields present.
        // If array is empty, like returned by {@see GetItemOutput::getItem()}, we can assume that the item does not exist and return null.
        if (!$output) {
            return null;
        }

        $fields = $this->deserializeFields($definition, [
            ...array_fill_keys($definition->getFieldNames(), null),
            ...$output,
        ]);

        foreach ($definition->getFieldDefinitions() as $name => $fieldDefinition) {
            if (
                !$fieldDefinition->allowsNull()
                && !$fieldDefinition->hasDefaultValue()
                && (!\array_key_exists($name, $fields) || $fields[$name] === null)
            ) {
                throw SerializerException::requiredDeserializationFieldValueMissing($fieldDefinition);
            }
        }

        return $definition->createInstance()->setVars($fields);
    }

    /**
     * Deserializes the provided fields. This is useful for partial DynamoDB
     * structures like LastEvaluatedKey where required entity fields are not present.
     *
     * @template Entity of AbstractEntity
     *
     * @param EntityDefinition<Entity> $definition
     * @param array<string, AttributeValue|null> $output
     *
     * @return array<string, mixed>
     */
    public function deserializeFields(EntityDefinition $definition, array $output): array
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

                $fields[$name] = null;

                continue;
            }

            try {
                $fields[$name] = $fieldDefinition->getSerializer()->deserialize($fieldDefinition, $attributeValue);
            } catch (\Throwable $e) {
                if ($e instanceof DALException) {
                    throw $e;
                }

                throw SerializerException::fieldDeserializationFailed($fieldDefinition->getSerializer()::class, $fieldDefinition, $attributeValue, $e);
            }
        }

        return $this->denormalize($definition, $fields);
    }

    /**
     * Serializes a whole item or only specified fields.
     *
     * @template Entity of AbstractEntity
     *
     * @param EntityDefinition<Entity> $definition
     * @param array<string, mixed> $fields
     *
     * @throws SerializerException if a provided field does not exist in the definition or a required field value is missing
     *
     * @return SerializedResult<EntityDefinition<Entity>>
     */
    public function serialize(EntityDefinition $definition, AbstractEntity|array $fields): SerializedResult
    {
        if ($fields instanceof AbstractEntity) {
            $defaultFields = array_fill_keys($definition->getFieldNames(), null);
            $fields = array_filter(
                [...$defaultFields, ...$fields->getVars()],
                static fn (string $name): bool => \array_key_exists($name, $defaultFields),
                \ARRAY_FILTER_USE_KEY,
            );
        }

        $fields = $this->normalize($definition, $fields);

        $result = [];
        foreach ($fields as $name => $value) {
            $path = FieldPath::tryParse($definition, $name);

            // A field name outside the definition is a typo, not a value to skip silently.
            if (!$path) {
                throw SerializerException::unknownFieldToSerialize($definition, $name);
            }

            // DynamoDB has no null attribute: an unset value is absent from a put, and removed by an update.
            if ($value === null && $path->definition->allowsNull()) {
                $result[$name] = new SerializedFieldResult($path, null);

                continue;
            }

            // The normalizer ran before this and did not fill it, so the value is genuinely unset.
            if ($value === null && !$path->definition->hasDefaultValue()) {
                throw SerializerException::requiredSerializationFieldValueMissing($path->definition);
            }

            try {
                $serialized = $path->definition->getSerializer()->serialize($path->definition, $value);
            } catch (\Throwable $e) {
                if ($e instanceof DALException) {
                    throw $e;
                }

                throw SerializerException::fieldSerializationFailed(
                    $path->definition->getSerializer()::class,
                    $path->definition,
                    $value,
                    $e,
                    $path->isNested() ? $path->path : null,
                );
            }

            $result[$name] = new SerializedFieldResult($path, $serialized);
        }

        return new SerializedResult($definition, $result, $fields);
    }

    /**
     * @template Entity of AbstractEntity
     *
     * @param EntityDefinition<Entity> $definition
     * @param Entity|Index $key
     *
     * @throws SerializerException if a provided field does not exist in the definition or a required field value is missing
     *
     * @return array<string, AttributeValue>
     */
    public function serializeKey(EntityDefinition $definition, AbstractEntity|Index $key): array
    {
        if ($key instanceof AbstractEntity) {
            $keySchema = $definition->getKeySchema();
            $vars = $key->getVars();
            $key = new Index(
                $vars[$keySchema->hashKey] ?? null,
                $keySchema->rangeKey !== null ? ($vars[$keySchema->rangeKey] ?? null) : null,
            );
        }

        $fields = $key->getFields($definition);
        $result = $this->serialize($definition, $fields)->getFields();

        // A DynamoDB key must carry every key attribute; a key field that serialized to nothing is a bug.
        foreach (array_keys($fields) as $name) {
            if (!isset($result[$name])) {
                $fieldDefinition = $definition->getFieldDefinition($name);
                if ($fieldDefinition === null) {
                    throw SerializerException::unknownFieldToSerialize($definition, $name);
                }

                throw SerializerException::fieldValueMissingAfterSerialization($fieldDefinition);
            }
        }

        return $result;
    }

    /**
     * @template Entity of AbstractEntity
     *
     * @param EntityDefinition<Entity> $definition
     * @param array<string, AttributeValue|null> $output
     *
     * @return Index|null - Returns null if the key cannot be build from the provided fields, e.g. empty or key schema not matching
     */
    public function deserializeKey(EntityDefinition $definition, array $output): ?Index
    {
        $keySchema = $definition->getKeySchema();

        if (!isset($output[$keySchema->hashKey]) || ($keySchema->rangeKey !== null && !isset($output[$keySchema->rangeKey]))) {
            return null;
        }

        $fields = [$keySchema->hashKey => $output[$keySchema->hashKey]];
        if ($keySchema->rangeKey !== null) {
            $fields[$keySchema->rangeKey] = $output[$keySchema->rangeKey];
        }

        $deserialized = $this->deserializeFields($definition, $fields);

        $rangeValue = $keySchema->rangeKey !== null ? ($deserialized[$keySchema->rangeKey] ?? null) : null;

        return new Index($deserialized[$keySchema->hashKey] ?? null, $rangeValue);
    }

    /**
     * @template Entity of AbstractEntity
     *
     * @param EntityDefinition<Entity> $definition
     *
     * @throws SerializerException if a key field does not exist in the definition or a value is missing
     *
     * @return array<string, AttributeValue>
     */
    public function serializeCursor(EntityDefinition $definition, Cursor $cursor): array
    {
        $fields = $cursor->primaryKey->getFields($definition);
        if ($cursor->indexKey !== null) {
            $fields = [...$fields, ...$cursor->indexKey->getFields($definition)];
        }

        return $this->serialize($definition, $fields)->getFields();
    }

    /**
     * Normalizes a whole item or only specified fields.
     *
     * @param array<string, mixed> $fields - `['fieldName' => fieldValue]`
     *
     * @return array<string, mixed>
     */
    protected function normalize(EntityDefinition $definition, array $fields): mixed
    {
        if (!$definition->getNormalizer()) {
            return $fields;
        }

        $keys = array_keys($fields);
        $keys = array_combine($keys, $keys);

        return $definition->getNormalizer()->normalize($fields, $keys);
    }

    /**
     * Denormalizes whole item fields or only provided partial fields.
     *
     * @param array<string, mixed> $fields - `['fieldName' => fieldValue]`
     *
     * @return array<string, mixed>
     */
    protected function denormalize(EntityDefinition $definition, array $fields): mixed
    {
        if (!$definition->getNormalizer()) {
            return $fields;
        }

        $keys = array_keys($fields);
        $keys = array_combine($keys, $keys);

        return $definition->getNormalizer()->denormalize($fields, $keys);
    }
}
