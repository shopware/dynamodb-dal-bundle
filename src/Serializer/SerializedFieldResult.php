<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Serializer;

use Shopware\DynamodbDalBundle\Definition\FieldDefinition;
use Shopware\DynamodbDalBundle\Definition\FieldPath;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;

/**
 * @internal
 */
class SerializedFieldResult
{
    /**
     * Necessary to avoid collisions with compiled expression names. sv = Serialized Value
     */
    private const string PREFIX = 'sv';

    public function __construct(
        private readonly FieldPath $path,
        private readonly ?AttributeValue $value,
    ) {
    }

    public function getDefinition(): FieldDefinition
    {
        return $this->path->definition;
    }

    public function getValue(): ?AttributeValue
    {
        return $this->value;
    }

    /**
     * @return array<string, AttributeValue> - `['fieldName' => ['S' => 'fieldValue']]`
     */
    public function getFields(): array
    {
        if ($this->value === null) {
            return [];
        }

        return [$this->path->path => $this->value];
    }

    /**
     * @return array<string, AttributeValue> - `[':fieldName' => ['S' => 'fieldValue']]`
     */
    public function getExpressionAttributeValues(): array
    {
        if ($this->value === null) {
            return [];
        }

        return [$this->path->getAttributeValueName(self::PREFIX) => $this->value];
    }

    /**
     * @return array<string, string> - `['#fieldName' => 'fieldName']`
     */
    public function getExpressionAttributeNames(): array
    {
        return $this->path->getExpressionAttributeNames();
    }

    /**
     * @return array<string, string> - `['fieldName' => '#fieldAttributeName = :fieldValueName']`
     */
    public function getExpressions(): array
    {
        if ($this->value === null) {
            return [];
        }

        return [$this->path->path => "{$this->path->getExpression()} = {$this->path->getAttributeValueName(self::PREFIX)}"];
    }

    /**
     * @return array<string, string> - `['fieldName.nestedPath' => '#fieldName.#nestedPath']`
     */
    public function getRemoveExpressions(): array
    {
        if ($this->value !== null) {
            return [];
        }

        return [$this->path->path => $this->path->getExpression()];
    }
}
