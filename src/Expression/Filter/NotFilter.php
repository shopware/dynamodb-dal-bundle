<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Expression\Filter;

use Shopware\DynamodbDalBundle\Expression\Contract\FilterInterface;
use Shopware\DynamodbDalBundle\Expression\FilterCompileContext;

final readonly class NotFilter implements FilterInterface
{
    public function __construct(
        public FilterInterface $filter,
    ) {
    }

    public function compile(FilterCompileContext $context): ?string
    {
        $inner = $this->filter->compile($context);

        return $inner !== null ? "NOT {$inner}" : null;
    }
}
