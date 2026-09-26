<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Expression\Filter;

use Shopware\DynamodbDalBundle\Expression\Contract\FilterInterface;
use Shopware\DynamodbDalBundle\Expression\ExpressionCompileContext;

/**
 * Matches when the DynamoDB `size()` of an attribute (map/list/set element count, string length, …)
 * equals a number, e.g. `size(tags) = 0` to match an empty collection.
 */
final readonly class SizeEqualsFilter implements FilterInterface
{
    public function __construct(
        public string $fieldName,
        public int $value,
    ) {
    }

    public function compile(ExpressionCompileContext $context): string
    {
        return \sprintf(
            'size(%s) = %s',
            $context->path($this->fieldName),
            $context->number($this->value),
        );
    }
}
