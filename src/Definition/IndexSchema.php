<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Definition;

/**
 * A global secondary index declared on a {@see \Shopware\DynamodbDalBundle\Attribute\Table},
 * identified by its DynamoDB index name and carrying its own {@see KeySchema}.
 */
readonly class IndexSchema
{
    public KeySchema $keySchema;

    public function __construct(
        public string $name,
        string $hashKey,
        ?string $rangeKey = null,
    ) {
        $this->keySchema = new KeySchema($hashKey, $rangeKey);
    }
}
