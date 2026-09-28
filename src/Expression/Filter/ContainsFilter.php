<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Expression\Filter;

use Shopware\DynamodbDalBundle\Definition\AttributeType;
use Shopware\DynamodbDalBundle\Expression\Contract\FilterInterface;
use Shopware\DynamodbDalBundle\Expression\FilterCompileContext;

/**
 * Matches a string that contains a substring, or a list or set that contains an element.
 *
 * On a string field, a PHP string is sent as is, so it also finds a substring of a JSON field.
 * On a set, a PHP string or number of the set's type is sent as the member, and a string as its bytes for a binary set.
 * Any other value is serialized by the field, such as an enum by its value.
 * On a list, the value is serialized as one element.
 */
final readonly class ContainsFilter implements FilterInterface
{
    public function __construct(
        public string $fieldName,
        public mixed $value,
    ) {
    }

    public function compile(FilterCompileContext $context): string
    {
        $path = $context->path(
            $this->fieldName,
            AttributeType::String,
            AttributeType::List,
            AttributeType::StringSet,
            AttributeType::NumberSet,
            AttributeType::BinarySet,
        );

        $field = $context->fieldDefinition($this->fieldName);
        $type = $field->getAttributeType();
        $operand = match (true) {
            \is_string($this->value) && ($type === AttributeType::String || $type === AttributeType::StringSet) => $context->literal($this->value),
            (\is_int($this->value) || \is_float($this->value)) && $type === AttributeType::NumberSet => $context->literal($this->value),
            \is_string($this->value) && $type === AttributeType::BinarySet => $context->binaryLiteral($this->value),
            // DynamoDB contains(listField, value) expects `value` to be one list element.
            $field->getValueFieldDefinition() !== null => $context->elementValue($this->fieldName, $this->value),
            default => $context->fieldValue($this->fieldName, $this->value),
        };

        return "contains({$path}, {$operand})";
    }
}
