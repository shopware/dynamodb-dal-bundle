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
}
