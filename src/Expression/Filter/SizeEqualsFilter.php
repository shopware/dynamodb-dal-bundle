<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Expression\Filter;

use Shopware\DynamodbDalBundle\Expression\Contract\ExpressionInterface;
use Shopware\DynamodbDalBundle\Expression\ExpressionCompileContext;

/**
 * Matches when the DynamoDB `size()` of an attribute (map/list/set element count, string length, …)
 * equals a number, e.g. `size(tags) = 0` to match an empty collection.
 */
class SizeEqualsFilter implements ExpressionInterface
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
