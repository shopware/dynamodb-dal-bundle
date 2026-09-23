<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Criteria\Filter;

use Shopware\DynamodbDalBundle\Criteria\Contract\FilterInterface;
use Shopware\DynamodbDalBundle\Criteria\ExpressionCompileContext;

class BetweenFilter implements FilterInterface
{
    public function __construct(
        public readonly string $fieldName,
        public readonly mixed $fromValue,
        public readonly mixed $toValue,
    ) {
    }

    public function compile(ExpressionCompileContext $context): ?string
    {
        return \sprintf(
            '%s BETWEEN %s AND %s',
            $context->attribute($this->fieldName),
            $context->placeholder($this->fieldName, $this->fromValue),
            $context->placeholder($this->fieldName, $this->toValue),
        );
    }
}
