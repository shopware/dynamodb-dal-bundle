<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Expression\Filter;

use Shopware\DynamodbDalBundle\Expression\Contract\FilterInterface;
use Shopware\DynamodbDalBundle\Expression\ExpressionCompileContext;

final readonly class ContainsFilter implements FilterInterface
{
    public function __construct(
        public string $fieldName,
        public mixed $value,
    ) {
    }

    public function compile(ExpressionCompileContext $context): string
    {
        return \sprintf(
            'contains(%s, %s)',
            $context->path($this->fieldName),
            // DynamoDB contains(listField, value) expects `value` to be one list element.
            $context->value($this->fieldName, $this->value, useValueFieldDefinition: true),
        );
    }
}
