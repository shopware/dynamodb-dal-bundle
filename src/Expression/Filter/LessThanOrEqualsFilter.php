<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Expression\Filter;

use Shopware\DynamodbDalBundle\Expression\Contract\FilterInterface;
use Shopware\DynamodbDalBundle\Expression\ExpressionCompileContext;

class LessThanOrEqualsFilter implements FilterInterface
{
    public function __construct(
        public readonly string $fieldName,
        public readonly mixed $value,
    ) {
    }

    public function compile(ExpressionCompileContext $context): ?string
    {
        return "{$context->attribute($this->fieldName)} <= {$context->placeholder($this->fieldName, $this->value)}";
    }
}
