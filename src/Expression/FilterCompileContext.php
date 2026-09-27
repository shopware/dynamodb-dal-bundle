<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Expression;

use Shopware\DynamodbDalBundle\Definition\AttributeType;
use Shopware\DynamodbDalBundle\Exception\AttributeTypeMismatchException;
use Shopware\DynamodbDalBundle\Exception\DALException;
use Shopware\DynamodbDalBundle\Exception\NullOperandException;
use Shopware\DynamodbDalBundle\Exception\UnknownFieldException;
use Shopware\DynamodbDalBundle\Exception\WrongTypeException;
use Shopware\DynamodbDalBundle\Expression\Contract\FilterInterface;
use Shopware\DynamodbDalBundle\Expression\Filter\FieldOperand;
use Shopware\DynamodbDalBundle\Expression\Filter\SizeOperand;

/**
 * What a filter compiles against, see {@see FilterInterface::compile()}.
 * Adds the sides of a comparison, including `size()` and other fields.
 */
final class FilterCompileContext extends ExpressionCompileContext
{
    /**
     * Whether the filter just compiled joins clauses with `AND` or `OR`, so its parent wraps it in `(...)`, as in
     * `a AND (b OR c)`. A filter sets this rather than wrapping itself, as DynamoDB may refuse a key condition in
     * parentheses.
     */
    public bool $isCompound = false;

    /**
     * One side of a comparison: `#path`, or `size(#path)` for a {@see SizeOperand}. `$types` restricts its type.
     *
     * @throws UnknownFieldException
     * @throws AttributeTypeMismatchException also for the size of a field that has none, such as a number
     */
    public function operand(string|FieldOperand|SizeOperand $operand, AttributeType ...$types): string
    {
        if (!$operand instanceof SizeOperand) {
            return $this->path(\is_string($operand) ? $operand : $operand->fieldName, ...$types);
        }

        // A size is a number, whatever it measures
        if ($types !== [] && !\in_array(AttributeType::Number, $types, true)) {
            throw new AttributeTypeMismatchException($this->definition, "size({$operand->fieldName})", AttributeType::Number, array_values($types));
        }

        // DynamoDB measures strings, binaries and collections only
        $path = $this->path(
            $operand->fieldName,
            AttributeType::String,
            AttributeType::Binary,
            AttributeType::List,
            AttributeType::Map,
            AttributeType::StringSet,
            AttributeType::NumberSet,
            AttributeType::BinarySet,
        );

        return "size({$path})";
    }

    /**
     * The right-hand side of a comparison with `$operand`. A {@see FieldOperand} or {@see SizeOperand} has to be of
     * the same type as `$operand`. Any other value is serialized by the field, or as a number for a size.
     *
     * @throws UnknownFieldException
     * @throws AttributeTypeMismatchException
     * @throws NullOperandException
     * @throws WrongTypeException if a size is compared with anything but a number
     * @throws DALException if the value does not serialize for the field
     */
    public function comparand(string|SizeOperand $operand, mixed $value): string
    {
        if ($value instanceof FieldOperand || $value instanceof SizeOperand) {
            $type = \is_string($operand) ? $this->fieldDefinition($operand)->getAttributeType() : AttributeType::Number;

            return $this->operand($value, ...($type === null ? [] : [$type]));
        }

        if (\is_string($operand)) {
            return $this->fieldValue($operand, $value);
        }

        if (!\is_int($value) && !\is_float($value)) {
            $field = $this->fieldDefinition($operand->fieldName);

            throw $value === null ? new NullOperandException($field) : new WrongTypeException($field, 'int|float', $value);
        }

        return $this->literal($value);
    }
}
