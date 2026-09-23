<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Criteria\Filter;

use Shopware\DynamodbDalBundle\Criteria\Contract\FilterInterface;
use Shopware\DynamodbDalBundle\Criteria\ExpressionCompileContext;

/**
 * Matches when the DynamoDB `size()` of an attribute (map/list/set element count, string length, …)
 * equals a number, e.g. `size(tags) = 0` to match an empty collection.
 */
class SizeEqualsFilter implements FilterInterface
{
    public function __construct(
        public readonly string $fieldName,
        public readonly int $value,
    ) {
    }

    public function compile(ExpressionCompileContext $context): ?string
    {
        return \sprintf(
            'size(%s) = %s',
            $context->attribute($this->fieldName),
            $context->numberPlaceholder($this->value),
        );
    }
}
