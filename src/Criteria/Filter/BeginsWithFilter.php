<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Criteria\Filter;

use Shopware\DynamodbDalBundle\Criteria\Contract\FilterInterface;
use Shopware\DynamodbDalBundle\Criteria\ExpressionCompileContext;

class BeginsWithFilter implements FilterInterface
{
    public function __construct(
        public readonly string $fieldName,
        public readonly mixed $value,
    ) {
    }

    public function compile(ExpressionCompileContext $context): ?string
    {
        return \sprintf(
            'begins_with(%s, %s)',
            $context->attribute($this->fieldName),
            $context->placeholder($this->fieldName, $this->value),
        );
    }
}
