<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Expression\Filter;

use Shopware\DynamodbDalBundle\Definition\AttributeType;
use Shopware\DynamodbDalBundle\Expression\Contract\FilterInterface;
use Shopware\DynamodbDalBundle\Expression\FilterCompileContext;

final readonly class ComparisonFilter implements FilterInterface
{
    /**
     * @param mixed $value - a value, a {@see FieldOperand} or a {@see SizeOperand}
     */
    public function __construct(
        public string|SizeOperand $fieldName,
        public Comparator $comparator,
        public mixed $value,
    ) {
    }

    public function compile(FilterCompileContext $context): string
    {
        // DynamoDB orders strings, numbers and binaries only
        $types = $this->comparator === Comparator::Equals ? [] : [AttributeType::String, AttributeType::Number, AttributeType::Binary];

        return \sprintf(
            '%s %s %s',
            $context->operand($this->fieldName, ...$types),
            $this->comparator->value,
            $context->comparand($this->fieldName, $this->value),
        );
    }
}
