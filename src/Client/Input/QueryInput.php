<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Client\Input;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Client\Output\Page;
use Shopware\DynamodbDalBundle\Expression\Contract\FilterInterface;
use Shopware\DynamodbDalBundle\Expression\Filter;
use Shopware\DynamodbDalBundle\Expression\Filter\KeyFilter;

/**
 * A read request for querying a table via primary or secondary indexes.
 *
 * @template-covariant Entity of AbstractEntity
 */
final readonly class QueryInput
{
    /**
     * @param class-string<Entity> $class - the entity class whose table is queried
     * @param KeyFilter $keyCondition - query's `KeyConditionExpression`, on the key of the table or of `$index`: selects the items read. Built with {@see Filter::keyFilter()}
     * @param ?string $index - query's `IndexName`: the global secondary index to query, as declared on `#[Table]`, which the query is checked against; `null` queries the table
     * @param ?FilterInterface $filter - query's `FilterExpression`: drops items after they are read, so they still cost read capacity
     * @param bool $forward - query's `ScanIndexForward`: `false` returns the items in descending range key order
     * @param bool $consistentRead - query's `ConsistentRead`. Strongly consistent read; off by default. DynamoDB rejects it on a GSI, but LSI and PK queries accept it
     * @param ?string $cursor - a {@see Page::$next} or {@see Page::$previous} token of this same query; `null` starts from the beginning
     * @param ?int $limit - the most items to return; one below 1 counts as 1. With a filter, whole pages are read and cut to size here, as DynamoDB applies its `Limit` before filtering
     */
    public function __construct(
        public string $class,
        public KeyFilter $keyCondition,
        public ?string $index = null,
        public ?FilterInterface $filter = null,
        public bool $forward = true,
        public bool $consistentRead = false,
        public ?string $cursor = null,
        public ?int $limit = null,
    ) {
    }
}
