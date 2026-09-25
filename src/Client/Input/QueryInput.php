<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Client\Input;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Client\Output\Page;
use Shopware\DynamodbDalBundle\Expression\Contract\FilterInterface;

/**
 * A read request for querying a table via primary or secondary indexes.
 *
 * @template-covariant Entity of AbstractEntity
 */
final readonly class QueryInput
{
    /**
     * @param class-string<Entity> $class - the entity class whose table is queried
     * @param FilterInterface $keyCondition - query's `KeyConditionExpression`. Filter is applied before reading an entry
     * @param ?string $index - query's `Index`. Used to filter a GSI
     * @param ?FilterInterface $filter - query's `FilterExpression`. Filter is applied after reading an entry
     * @param bool $forward - query's `ScanIndexForward`. If set to false, the result will be in reverse order
     * @param bool $consistentRead - query's `ConsistentRead`. Strongly consistent read; off by default. DynamoDB rejects it on a GSI — only request it for a base-table query
     * @param ?string $cursor - a {@see Page::$next} or {@see Page::$previous} token of this same query; `null` starts from the beginning
     * @param ?int $limit - limit the amount of results returned
     */
    public function __construct(
        public string $class,
        public FilterInterface $keyCondition,
        public ?string $index = null,
        public ?FilterInterface $filter = null,
        public bool $forward = true,
        public bool $consistentRead = false,
        public ?string $cursor = null,
        public ?int $limit = null,
    ) {
    }
}
