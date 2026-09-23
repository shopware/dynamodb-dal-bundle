<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Criteria\Filter;

use Shopware\DynamodbDalBundle\Criteria\Contract\FilterInterface;
use Shopware\DynamodbDalBundle\Criteria\ExpressionCompileContext;

class ContainsFilter implements FilterInterface
{
    public function __construct(
        public readonly string $fieldName,
        public readonly mixed $value,
    ) {
    }

    public function compile(ExpressionCompileContext $context): ?string
    {
        return \sprintf(
            'contains(%s, %s)',
            $context->attribute($this->fieldName),
            // DynamoDB contains(listField, value) expects `value` to be one list element.
            $context->placeholder($this->fieldName, $this->value, useValueFieldDefinition: true),
        );
    }
}
