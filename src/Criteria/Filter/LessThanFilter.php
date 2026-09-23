<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Criteria\Filter;

use Shopware\DynamodbDalBundle\Criteria\Contract\FilterInterface;
use Shopware\DynamodbDalBundle\Criteria\ExpressionCompileContext;

class LessThanFilter implements FilterInterface
{
    public function __construct(
        public readonly string $fieldName,
        public readonly mixed $value,
    ) {
    }

    public function compile(ExpressionCompileContext $context): ?string
    {
        return "{$context->attribute($this->fieldName)} < {$context->placeholder($this->fieldName, $this->value)}";
    }
}
