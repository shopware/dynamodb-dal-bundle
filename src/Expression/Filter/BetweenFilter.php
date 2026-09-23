<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Expression\Filter;

use Shopware\DynamodbDalBundle\Expression\Contract\ExpressionInterface;
use Shopware\DynamodbDalBundle\Expression\ExpressionCompileContext;

class BetweenFilter implements ExpressionInterface
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
