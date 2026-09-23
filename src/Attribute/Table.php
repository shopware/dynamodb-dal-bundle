<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Attribute;

use Shopware\DynamodbDalBundle\Definition\IndexSchema;

/**
 * @codeCoverageIgnore
 */
#[\Attribute(flags: \Attribute::TARGET_CLASS)]
class Table
{
    /**
     * @param string $hashKey - Entity field name of the table partition (hash) key
     * @param string|null $rangeKey - Entity field name of the table sort (range) key, or null for a partition-only table
     * @param string|null $normalizer - Symfony service ID of a class that extends {@see Shopware\DynamodbDalBundle\Serializer\AbstractNormalizer}, or null if no normalizer should be used for this table
     * @param list<IndexSchema> $indexes - Global secondary indexes declared on this table
     */
    public function __construct(
        public readonly string $name,
        public readonly string $hashKey,
        public readonly ?string $rangeKey = null,
        public readonly ?string $normalizer = null,
        public readonly array $indexes = [],
    ) {
    }
}
