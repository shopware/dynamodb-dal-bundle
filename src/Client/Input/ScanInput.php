<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Client\Input;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Client\Output\Page;
use Shopware\DynamodbDalBundle\Expression\Contract\FilterInterface;

/**
 * A read request that scans a whole table, optionally filtered.
 *
 * @template-covariant Entity of AbstractEntity
 */
final readonly class ScanInput
{
    /**
     * @param class-string<Entity> $class - the entity class whose table is scanned
     * @param ?FilterInterface $filter - scan's `FilterExpression`: drops items after they are read, so they still cost read capacity
     * @param bool $consistentRead - scan's `ConsistentRead`. Strongly consistent read; off by default (eventually consistent reads are cheaper)
     * @param ?string $cursor - a {@see Page::$next} token of this same scan; `null` starts from the beginning
     * @param ?int $limit - the most items to return; one below 1 counts as 1. With a filter, whole pages are read and cut to size here, as DynamoDB applies its `Limit` before filtering
     */
    public function __construct(
        public string $class,
        public ?FilterInterface $filter = null,
        public bool $consistentRead = false,
        public ?string $cursor = null,
        public ?int $limit = null,
    ) {
    }
}
