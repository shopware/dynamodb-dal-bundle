<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Expression\Filter;

use Shopware\DynamodbDalBundle\Expression\Contract\FilterInterface;
use Shopware\DynamodbDalBundle\Expression\ExpressionCompileContext;

final readonly class NotFilter implements FilterInterface
{
    public function __construct(
        public FilterInterface $filter,
    ) {
    }

    public function compile(ExpressionCompileContext $context): ?string
    {
        $context->isCompound = false;
        $inner = $this->filter->compile($context);
        if ($inner === null) {
            return null;
        }

        /** @phpstan-ignore-next-line ternary.alwaysFalse -- the flag can change in `->compile` calls */
        $expression = $context->isCompound ? "NOT ({$inner})" : "NOT {$inner}";

        // `NOT ...` is one operand, whatever the inner filter was
        $context->isCompound = false;

        return $expression;
    }
}
