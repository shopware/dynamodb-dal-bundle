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
     * @return array{Item: array<string, AttributeValue>}
     */
    public function getPutExpression(): array
    {
        return ['Item' => $this->getFields()];
    }
}
