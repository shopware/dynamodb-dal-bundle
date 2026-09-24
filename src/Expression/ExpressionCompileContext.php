<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Expression;

use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\FieldPath;
use Shopware\DynamodbDalBundle\Exception\DALException;
use Shopware\DynamodbDalBundle\Exception\FieldSerializationException;
use Shopware\DynamodbDalBundle\Exception\NullFilterValueException;
use Shopware\DynamodbDalBundle\Exception\UnknownFieldException;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;

class ExpressionCompileContext
{
    /**
     * Collected by {@see placeholder()} and {@see numberPlaceholder()}; read by the compiler.
     *
     * @internal
     *
     * @var array<string, AttributeValue>
     */
    public array $values = [];

    /**
     * Collected by {@see attribute()}; read by the compiler.
     *
     * @internal
     *
     * @var array<string, string>
     */
    public array $names = [];

    /**
     * Set by And/Or after `compile()` to mark whether their output is a multi-clause boolean
     * expression. A sibling And/Or reads this to decide whether to wrap the child in `(...)`
     * for precedence (e.g. `a AND (b OR c)`). Top-level callers leave it untouched: DynamoDB's
     * KeyConditionExpression rejects an outer `(...)`, so we never wrap unconditionally.
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
     * Register the path with placeholder names under `#{prefix}_{N}` placeholder
     *
     * @throws UnknownFieldException
     */
    public function attribute(string $fieldName): string
    {
        $path = FieldPath::tryParse($this->definition, $fieldName) ?? throw new UnknownFieldException($this->definition, $fieldName);

        $this->names = [...$this->names, ...$path->getExpressionAttributeNames()];

        return $path->getExpression();
    }

    /**
     * Serialize the value via the (nested) field's serializer and register it under a unique `:{prefix}_{path}_{N}` placeholder.
     *
     * $useValueFieldDefinition is for DynamoDB functions that compare one collection element instead of the collection field itself.
     *
     * @throws UnknownFieldException
     * @throws NullFilterValueException
     * @throws DALException if the value does not serialize for the field
     */
    public function placeholder(string $fieldName, mixed $value, bool $useValueFieldDefinition = false): string
    {
        $path = FieldPath::tryParse($this->definition, $fieldName) ?? throw new UnknownFieldException($this->definition, $fieldName);
        $field = $path->definition;

        if ($useValueFieldDefinition) {
            $field = $field->getValueFieldDefinition() ?? $field;
        }

        if ($value === null) {
            throw new NullFilterValueException($field);
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
     * Register a raw number under a unique `:{prefix}_{N}` placeholder, bypassing the field serializer.
     *
     * DynamoDB functions like `size()` evaluate to a number regardless of the compared attribute's
     * own type (a map, list, string, …), so their operand cannot be serialized via that field.
     */
    public function numberPlaceholder(int|float $value): string
    {
        $placeholder = ":{$this->prefix}_" . \count($this->values);
        $this->values[$placeholder] = AttributeValue::create(['N' => (string) $value]);

        return $placeholder;
    }
}
