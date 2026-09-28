<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Expression;

use Shopware\DynamodbDalBundle\Definition\AttributeType;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\FieldDefinition;
use Shopware\DynamodbDalBundle\Definition\FieldPath;
use Shopware\DynamodbDalBundle\Exception\AttributeTypeMismatchException;
use Shopware\DynamodbDalBundle\Exception\DALException;
use Shopware\DynamodbDalBundle\Exception\FieldSerializationException;
use Shopware\DynamodbDalBundle\Exception\NullOperandException;
use Shopware\DynamodbDalBundle\Exception\UnknownFieldException;
use Shopware\DynamodbDalBundle\Expression\Contract\UpdateActionInterface;
use Shopware\DynamodbDalBundle\Serializer\Field\AbstractFieldSerializer;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;

/**
 * What an update action compiles against, see {@see UpdateActionInterface::compile()}: it registers the paths and
 * values an expression uses, and hands back the placeholders to write in their place.
 * Filters get the {@see FilterCompileContext} subclass.
 */
class ExpressionCompileContext
{
    /**
     * Collected by {@see fieldValue()}, {@see elementValue()} and {@see literal()}; read by the compiler.
     *
     * @internal
     *
     * @var array<string, AttributeValue>
     */
    public array $values = [];

    /**
     * Collected by {@see path()}; read by the compiler.
     *
     * @internal
     *
     * @var array<string, string>
     */
    public array $names = [];

    /**
     * @internal
     */
    public function __construct(
        public readonly EntityDefinition $definition,
        public readonly string $prefix,
    ) {
    }

    /**
     * Registers the attribute names of the path, and returns the path as an expression spells it: `#settings.#currency`
     * for `settings.currency`. A name placeholder is derived from the name, so the same path always registers alike.
     *
     * `$types` restricts the stored type, such as a list for `list_append()`.
     * A field without a declared type always passes, see {@see AbstractFieldSerializer::getAttributeType()}.
     *
     * @throws UnknownFieldException
     * @throws AttributeTypeMismatchException
     */
    public function path(string $fieldName, AttributeType ...$types): string
    {
        $path = FieldPath::parse($this->definition, $fieldName);
        $this->assertType($fieldName, $path->definition->getAttributeType(), $types);

        $this->names = [...$this->names, ...$path->getExpressionAttributeNames()];

        return $path->getExpression();
    }

    /**
     * The definition the path ends on: the field, or the value definition of a nested path.
     *
     * @throws UnknownFieldException
     */
    public function fieldDefinition(string $fieldName): FieldDefinition
    {
        return FieldPath::parse($this->definition, $fieldName)->definition;
    }

    /**
     * Serializes the value with the (nested) field's serializer, and registers it under a unique `:{prefix}_{N}_{path}`
     * placeholder, which it returns.
     *
     * @throws UnknownFieldException
     * @throws NullOperandException
     * @throws DALException if the value does not serialize for the field
     */
    public function fieldValue(string $fieldName, mixed $value): string
    {
        $path = FieldPath::parse($this->definition, $fieldName);

        return $this->serialize($path, $path->definition, $value);
    }

    /**
     * Like {@see fieldValue()}, but serializes the value as one element of the list or map field, such as the element
     * `contains()` looks for. A field without a declared type passes, as in {@see path()}, and one without elements
     * serializes the value as the field.
     *
     * @throws UnknownFieldException
     * @throws AttributeTypeMismatchException for a field that is no list or map
     * @throws NullOperandException
     * @throws DALException if the value does not serialize for an element of the field
     */
    public function elementValue(string $fieldName, mixed $value): string
    {
        $path = FieldPath::parse($this->definition, $fieldName);
        $this->assertType($fieldName, $path->definition->getAttributeType(), [AttributeType::List, AttributeType::Map]);

        return $this->serialize($path, $path->definition->getValueFieldDefinition() ?? $path->definition, $value);
    }

    /**
     * Registers the value as it is under a unique `:{prefix}_{N}` placeholder: a string as `S`, a number as `N`.
     *
     * For an operand that is not a value of a field, such as the prefix of `begins_with()` or the number a size is
     * compared with.
     */
    public function literal(string|int|float $value): string
    {
        $placeholder = ":{$this->prefix}_" . \count($this->values);
        $this->values[$placeholder] = AttributeValue::create(match (true) {
            \is_string($value) => ['S' => $value],
            // The digits a float field stores, as both follow `serialize_precision`; a cast to string rounds to `precision`
            \is_float($value) => ['N' => var_export($value, true)],
            default => ['N' => (string) $value],
        });

        return $placeholder;
    }

    /**
     * Registers the bytes under a unique `:{prefix}_{N}` placeholder as a binary (`B`), which {@see literal()} cannot
     * tell from a string.
     *
     * For an operand that is not a value of a field, such as the member `contains()` looks for in a binary set.
     */
    public function binaryLiteral(string $bytes): string
    {
        $placeholder = ":{$this->prefix}_" . \count($this->values);
        $this->values[$placeholder] = AttributeValue::create(['B' => $bytes]);

        return $placeholder;
    }

    /**
     * @throws NullOperandException
     * @throws DALException if the value does not serialize for the field
     */
    private function serialize(FieldPath $path, FieldDefinition $field, mixed $value): string
    {
        if ($value === null) {
            throw new NullOperandException($field);
        }

        try {
            $attributeValue = $field->getSerializer()->serialize($field, $value);
        } catch (\Throwable $e) {
            if ($e instanceof DALException) {
                throw $e;
            }

            throw new FieldSerializationException($field, $e, $path->path);
        }

        $placeholder = $path->getAttributeValueName($this->prefix . '_' . \count($this->values));
        $this->values[$placeholder] = $attributeValue;

        return $placeholder;
    }

    /**
     * @param array<AttributeType> $expected - none to accept any type
     *
     * @throws AttributeTypeMismatchException
     */
    private function assertType(string $field, ?AttributeType $actual, array $expected): void
    {
        if ($actual !== null && $expected !== [] && !\in_array($actual, $expected, true)) {
            throw new AttributeTypeMismatchException($this->definition, $field, $actual, array_values($expected));
        }
    }
}
