<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Definition;

/**
 * The partition (and optional sort) key of a table or index, addressed by entity field name.
 */
readonly class KeySchema
{
    public function __construct(
        public string $hashKey,
        public ?string $rangeKey = null,
    ) {
    }

    /**
     * The key field names, partition first, sort (if any) second.
     *
     * @return list<string>
     */
    public function getFields(): array
    {
        return $this->rangeKey === null
            ? [$this->hashKey]
            : [$this->hashKey, $this->rangeKey];
    }
}
