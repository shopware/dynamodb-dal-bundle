<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Expression\Filter;

use Shopware\DynamodbDalBundle\Expression\Contract\ExpressionInterface;
use Shopware\DynamodbDalBundle\Expression\ExpressionCompileContext;

class NotFilter implements ExpressionInterface
{
    public function __construct(
        public readonly ExpressionInterface $filter,
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
        $context->isCompound = false; // compile could have changed it

        return $expression;
    }
}
