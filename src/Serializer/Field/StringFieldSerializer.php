<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Serializer\Field;

use Shopware\DynamodbDalBundle\Definition\FieldDefinition;
use Shopware\DynamodbDalBundle\Exception\MissingAttributeValueException;
use Shopware\DynamodbDalBundle\Exception\WrongTypeException;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;

/**
 * @extends AbstractFieldSerializer<string, 'string'>
 */
class StringFieldSerializer extends AbstractFieldSerializer
{
    public static function supports(string $type, ?string $docblockType = null): bool
    {
        return $type === 'string';
    }

    public function serialize(FieldDefinition $definition, mixed $value): AttributeValue
    {
        if (!\is_string($value)) {
            throw new WrongTypeException($definition, 'string', $value);
        }

        return AttributeValue::create(['S' => $value]);
    }

    public function deserialize(FieldDefinition $definition, AttributeValue $attributeValue): mixed
    {
        if (($value = $attributeValue->getS()) === null) {
            throw new MissingAttributeValueException($definition, 'S');
        }

        return $value;
    }
}
