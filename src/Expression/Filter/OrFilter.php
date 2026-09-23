<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Expression\Filter;

use Shopware\DynamodbDalBundle\Expression\Contract\ExpressionInterface;
use Shopware\DynamodbDalBundle\Expression\ExpressionCompileContext;

class OrFilter implements ExpressionInterface
{
    /**
     * @var list<ExpressionInterface>
     */
    public array $filters;

    public function __construct(ExpressionInterface ...$filters)
    {
        $this->filters = array_values($filters);
    }

    public function or(ExpressionInterface ...$filters): self
    {
        $filters = array_values($filters);
        $this->filters = array_values(array_merge($this->filters, $filters));

        return $this;
    }

    public function compile(ExpressionCompileContext $context): ?string
    {
        $compiled = [];
        foreach ($this->filters as $filter) {
            $context->isCompound = false;
            $fragment = $filter->compile($context);
            if ($fragment === null) {
                continue;
            }

            /** @phpstan-ignore-next-line if.alwaysFalse -- the flag can change in `->compile` calls */
            if ($context->isCompound) {
                $fragment = "({$fragment})";
            }

            $compiled[] = $fragment;
        }

        $context->isCompound = \count($compiled) > 1;

        return match (\count($compiled)) {
            0 => null,
            1 => $compiled[0],
            default => implode(' OR ', $compiled),
        };
    }
}
