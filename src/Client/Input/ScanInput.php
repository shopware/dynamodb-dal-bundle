<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Client\Input;

use Shopware\DynamodbDalBundle\Client\Output\Page;
use Shopware\DynamodbDalBundle\Expression\Contract\ExpressionInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * A read request for scanning a table via a filter expression.
 */
#[Exclude]
final class ScanInput
{
    /**
     * @param ?ExpressionInterface $filter - scan's `FilterExpression`. Filter is applied after reading an entry
     * @param ?bool $consistentRead - scan's `ConsistentRead`. Strongly consistent read; off by default (eventually consistent reads are cheaper)
     * @param ?string $cursor - a {@see Page::$next} token of this same scan; `null` starts from the beginning
     * @param ?int $limit - limit the amount of results returned
     */
    public function __construct(
        public readonly ?ExpressionInterface $filter = null,
        public readonly ?bool $consistentRead = null,
        public readonly ?string $cursor = null,
        public readonly ?int $limit = null,
    ) {
    }
}
