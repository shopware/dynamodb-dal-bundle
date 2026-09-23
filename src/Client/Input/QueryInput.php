<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Client\Input;

use Shopware\DynamodbDalBundle\Client\Cursor\Cursor;
use Shopware\DynamodbDalBundle\Criteria\Contract\FilterInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * A read request for querying a table via primary or secondary indexes.
 */
#[Exclude]
final class QueryInput
{
    /**
     * @param FilterInterface $keyCondition - query's `KeyConditionExpression`. Filter is applied before reading an entry
     * @param ?string $index - query's `Index`. Used to filter a GSI
     * @param ?FilterInterface $filter - query's `FilterExpression`. Filter is applied after reading an entry
     * @param bool $forward - query's `ScanIndexForward`. If set to false, the result will be in reverse order
     * @param ?bool $consistentRead - query's `ConsistentRead`. Strongly consistent read; off by default. DynamoDB rejects it on a GSI — only request it for a base-table query
     * @param ?Cursor $cursor - query's `ExclusiveStartKey`. Resumes the query after a previous boundary item; `null` starts from the beginning
     * @param ?int $limit - limit the amount of results returned
     */
    public function __construct(
        public readonly FilterInterface $keyCondition,
        public readonly ?string $index = null,
        public readonly ?FilterInterface $filter = null,
        public readonly bool $forward = true,
        public readonly ?bool $consistentRead = null,
        public readonly ?Cursor $cursor = null,
        public readonly ?int $limit = null,
    ) {
    }
}
