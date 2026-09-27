<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Serializer;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;

/**
 * An item's primary key, serialized, and the hash that tells it apart from the key of any other item: its physical
 * table and key attributes, a number in the form DynamoDB compares it in (see {@see CanonicalKeyValue}).
 *
 * @internal
 *
 * @template-covariant Entity of AbstractEntity
 */
final readonly class SerializedKeyResult
{
    /**
     * @param EntityDefinition<Entity> $definition
     * @param array<string, AttributeValue> $fields - `['fieldName' => ['S' => 'fieldValue']]`, as DynamoDB takes a `Key`
     */
    private function __construct(
        public EntityDefinition $definition,
        public array $fields,
        public string $hash,
    ) {
    }

    /**
     * The key of an item: the attributes of the key schema, picked from a whole item or a serialized key.
     *
     * @template ItemEntity of AbstractEntity
     *
     * @param EntityDefinition<ItemEntity> $definition
     * @param array<string, AttributeValue> $item
     *
     * @return self<ItemEntity>
     */
    public static function fromItem(EntityDefinition $definition, array $item): self
    {
        $fields = [];
        $parts = [$definition->getTable()];
        foreach ($definition->getKeySchema()->getFields() as $field) {
            $value = $item[$field] ?? null;
            if ($value !== null) {
                $fields[$field] = $value;
            }

            $parts[] = $value !== null ? CanonicalKeyValue::of($value) : '';
        }

        // Each part leads with its length, so that no value can run into the next, whatever it holds
        $hash = implode('', array_map(static fn (string $part): string => \strlen($part) . ':' . $part, $parts));

        return new self($definition, $fields, $hash);
    }
}
