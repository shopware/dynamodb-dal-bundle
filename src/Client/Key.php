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
}
