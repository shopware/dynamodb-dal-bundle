<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Expression;

use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\FieldPath;
use Shopware\DynamodbDalBundle\Exception\DALException;
use Shopware\DynamodbDalBundle\Exception\FieldSerializationException;
use Shopware\DynamodbDalBundle\Exception\NullOperandException;
use Shopware\DynamodbDalBundle\Exception\UnknownFieldException;
use Shopware\DynamodbDalBundle\Expression\Contract\FilterInterface;
use Shopware\DynamodbDalBundle\Expression\Contract\UpdateActionInterface;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;

/**
 * What a filter or an update action compiles against, see {@see FilterInterface::compile()} and
 * {@see UpdateActionInterface::compile()}: it registers the paths and values an expression uses, and hands back the
 * placeholders to write in their place.
 */
class ExpressionCompileContext
{
    /**
     * Collected by {@see value()} and {@see number()}; read by the compiler.
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
     * Whether the filter just compiled is a multi-clause boolean expression, as And/Or set it after `compile()`.
     * A parent And/Or reads it to wrap that child in `(...)` for precedence, e.g. `a AND (b OR c)`, and the
     * compiler to join a whole condition with another.
     *
     * Nothing wraps a whole expression, as DynamoDB may refuse a key condition in parentheses.
     *
     * @internal
     */
    public bool $isCompound = false;

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
     * @throws UnknownFieldException
     */
    public function path(string $fieldName): string
    {
        $path = FieldPath::tryParse($this->definition, $fieldName) ?? throw new UnknownFieldException($this->definition, $fieldName);

        $this->names = [...$this->names, ...$path->getExpressionAttributeNames()];

        return $path->getExpression();
    }

    /**
     * Serializes the value with the (nested) field's serializer, and registers it under a unique `:{prefix}_{N}_{path}`
     * placeholder, which it returns.
     *
     * $useValueFieldDefinition is for DynamoDB functions that compare one collection element instead of the collection field itself.
     *
     * @throws UnknownFieldException
     * @throws NullOperandException
     * @throws DALException if the value does not serialize for the field
     */
    public function value(string $fieldName, mixed $value, bool $useValueFieldDefinition = false): string
    {
        $path = FieldPath::tryParse($this->definition, $fieldName) ?? throw new UnknownFieldException($this->definition, $fieldName);
        $field = $path->definition;

        if ($useValueFieldDefinition) {
            $field = $field->getValueFieldDefinition() ?? $field;
        }

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
     * Registers a raw number under a unique `:{prefix}_{N}` placeholder, bypassing the field serializer.
     *
     * DynamoDB functions like `size()` evaluate to a number regardless of the compared attribute's
     * own type (a map, list, string, …), so their operand cannot be serialized via that field.
     */
    public function number(int|float $value): string
    {
        $placeholder = ":{$this->prefix}_" . \count($this->values);
        $this->values[$placeholder] = AttributeValue::create(['N' => (string) $value]);

        return $placeholder;
    }
}
