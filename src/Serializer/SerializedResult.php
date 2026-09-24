<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Serializer;

use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;

/**
 * @internal
 *
 * @template-covariant Definition of EntityDefinition = EntityDefinition
 */
class SerializedResult
{
    /**
     * @param Definition $definition
     * @param array<string, SerializedFieldResult> $fields
     * @param array<string, mixed> $normalizedFields
     * @param NormalizerOperation $operation - what the fields were normalized for
     */
    public function __construct(
        protected readonly EntityDefinition $definition,
        protected readonly array $fields,
        protected readonly array $normalizedFields,
        protected readonly NormalizerOperation $operation,
    ) {
    }

    public function getEntityDefinition(): EntityDefinition
    {
        return $this->definition;
    }

    /**
     * @return array<string, mixed>
     */
    public function getNormalizedFields(): array
    {
        return $this->normalizedFields;
    }

    public function getOperation(): NormalizerOperation
    {
        return $this->operation;
    }

    /**
     * Whether any field addresses a spot inside an attribute.
     */
    public function hasNestedFields(): bool
    {
        return array_any($this->fields, static fn (SerializedFieldResult $field): bool => $field->isNested());
    }

    /**
     * @return array<string, AttributeValue> - `['fieldName' => ['S' => 'fieldValue']]`
     */
    public function getFields(): array
    {
        $result = [];
        foreach ($this->fields as $field) {
            $result = [...$result, ...$field->getFields()];
        }

        return $result;
    }

    /**
     * @return array<string, AttributeValue> - `[':fieldName' => ['S' => 'fieldValue']]`
     */
    public function getExpressionAttributeValues(): array
    {
        $result = [];
        foreach ($this->fields as $field) {
            $result = [...$result, ...$field->getExpressionAttributeValues()];
        }

        return $result;
    }

    /**
     * @return array<string, string> - `['#fieldName' => 'fieldName']`
     */
    public function getExpressionAttributeNames(): array
    {
        $result = [];
        foreach ($this->fields as $field) {
            $result = [...$result, ...$field->getExpressionAttributeNames()];
        }

        return $result;
    }

    /**
     * @return array<string, string> - `['fieldName' => '#fieldAttributeName = :fieldValueName']`
     */
    public function getExpressions(): array
    {
        $result = [];
        foreach ($this->fields as $field) {
            $result = [...$result, ...$field->getExpressions()];
        }

        return $result;
    }

    /**
     * @return array<string, string> - `['fieldName.nestedPath' => '#fieldName.#nestedPath']`
     */
    public function getRemoveExpressions(): array
    {
        $result = [];
        foreach ($this->fields as $field) {
            $result = [...$result, ...$field->getRemoveExpressions()];
        }

        return $result;
    }

    /**
     * @return array{Item: array<string, AttributeValue>}
     */
    public function getPutExpression(): array
    {
        return ['Item' => $this->getFields()];
    }

    /**
     * @return array{
     *     ExpressionAttributeNames?: array<string, string>,
     *     ExpressionAttributeValues?: array<string, AttributeValue>,
     *     UpdateExpression: string,
     * }
     */
    public function getUpdateExpression(): array
    {
        $parts = [];

        if ($setExpressions = $this->getExpressions()) {
            $parts[] = 'SET ' . implode(', ', $setExpressions);
        }

        if ($removeExpressions = $this->getRemoveExpressions()) {
            $parts[] = 'REMOVE ' . implode(', ', $removeExpressions);
        }

        $expression = ['UpdateExpression' => implode(' ', $parts)];

        if ($names = $this->getExpressionAttributeNames()) {
            $expression['ExpressionAttributeNames'] = $names;
        }

        if ($values = $this->getExpressionAttributeValues()) {
            $expression['ExpressionAttributeValues'] = $values;
        }

        return $expression;
    }
}
