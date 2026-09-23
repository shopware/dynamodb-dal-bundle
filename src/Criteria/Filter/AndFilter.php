<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Criteria\Filter;

use Shopware\DynamodbDalBundle\Criteria\Contract\FilterInterface;
use Shopware\DynamodbDalBundle\Criteria\ExpressionCompileContext;

class AndFilter implements FilterInterface
{
    /**
     * @var list<FilterInterface>
     */
    public array $filters;

    public function __construct(FilterInterface ...$filters)
    {
        $this->filters = array_values($filters);
    }

    public function and(FilterInterface ...$filters): self
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
            default => implode(' AND ', $compiled),
        };
    }
}
