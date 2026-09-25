<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Client;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;

/**
 * An item's primary key. The values are PHP values, serialized by the key fields' serializers like any other field.
 * The sort (range) value is ignored for a table without a sort key.
 *
 * @template-covariant Entity of AbstractEntity
 */
final readonly class Key
{
    /**
     * @param class-string<Entity> $class
     */
    public function __construct(
        public string $class,
        public mixed $hashValue,
        public mixed $rangeValue = null,
    ) {
    }

    /**
     * @internal - the serializer maps a key onto its fields; callers address entities by class
     *
     * The key as a `[fieldName => value]` map of the table's key schema.
     *
     * @param EntityDefinition<AbstractEntity> $definition
     *
     * @return array<string, mixed>
     */
    public function getFields(EntityDefinition $definition): array
    {
        $keySchema = $definition->getKeySchema();

        $fields = [$keySchema->hashKey => $this->hashValue];
        if ($keySchema->rangeKey !== null) {
            $fields[$keySchema->rangeKey] = $this->rangeValue;
        }

        return $fields;
    }
}
