<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Client;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;

/**
 * A key address: the partition (hash) value and, for a table that declares one, the sort (range) value.
 * `$index` selects which key it addresses — `null` is the base-table primary key, a name is that GSI's
 * key.
 */
final class Index
{
    public function __construct(
        public readonly mixed $hashValue,
        public readonly mixed $rangeValue = null,
        public readonly ?string $index = null,
    ) {
    }

    /**
     * @internal - the serializer maps a key onto its fields; callers address entities by class
     *
     * The key as a `[fieldName => value]` map, using the base key schema (or the named GSI's). `[]` if the
     * named index is not declared.
     *
     * @param EntityDefinition<AbstractEntity> $definition
     *
     * @return array<string, mixed>
     */
    public function getFields(EntityDefinition $definition): array
    {
        $keySchema = $this->index === null
            ? $definition->getKeySchema()
            : $definition->getIndex($this->index)?->keySchema;

        if ($keySchema === null) {
            return [];
        }

        $fields = [$keySchema->hashKey => $this->hashValue];
        if ($keySchema->rangeKey !== null) {
            $fields[$keySchema->rangeKey] = $this->rangeValue;
        }

        return $fields;
    }
}
