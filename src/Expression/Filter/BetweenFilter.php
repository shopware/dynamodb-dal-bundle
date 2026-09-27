<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Expression\Filter;

use Shopware\DynamodbDalBundle\Definition\AttributeType;
use Shopware\DynamodbDalBundle\Expression\Contract\FilterInterface;
use Shopware\DynamodbDalBundle\Expression\FilterCompileContext;

final readonly class BetweenFilter implements FilterInterface
{
    /**
     * @param mixed $fromValue - a value, a {@see FieldOperand} or a {@see SizeOperand}
     * @param mixed $toValue - a value, a {@see FieldOperand} or a {@see SizeOperand}
     */
    public function __construct(
        public string|SizeOperand $fieldName,
        public mixed $fromValue,
        public mixed $toValue,
    ) {
    }

    public function compile(FilterCompileContext $context): string
    {
        // DynamoDB orders strings, numbers and binaries only
        return \sprintf(
            '%s BETWEEN %s AND %s',
            $context->operand($this->fieldName, AttributeType::String, AttributeType::Number, AttributeType::Binary),
            $context->comparand($this->fieldName, $this->fromValue),
            $context->comparand($this->fieldName, $this->toValue),
        );
    }
}
