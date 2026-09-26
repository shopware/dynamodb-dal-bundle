<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Expression\Filter;

use Shopware\DynamodbDalBundle\Expression\Contract\FilterInterface;
use Shopware\DynamodbDalBundle\Expression\ExpressionCompileContext;

final readonly class BetweenFilter implements FilterInterface
{
    public function __construct(
        public string $fieldName,
        public mixed $fromValue,
        public mixed $toValue,
    ) {
    }

    public function compile(ExpressionCompileContext $context): string
    {
        return \sprintf(
            '%s BETWEEN %s AND %s',
            $context->path($this->fieldName),
            $context->value($this->fieldName, $this->fromValue),
            $context->value($this->fieldName, $this->toValue),
        );
    }
}
