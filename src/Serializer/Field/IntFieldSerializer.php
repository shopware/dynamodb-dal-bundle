<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Serializer\Field;

use Shopware\DynamodbDalBundle\Definition\FieldDefinition;
use Shopware\DynamodbDalBundle\Exception\MissingAttributeValueException;
use Shopware\DynamodbDalBundle\Exception\WrongTypeException;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;

/**
 * @extends AbstractFieldSerializer<int, 'int'>
 */
class IntFieldSerializer extends AbstractFieldSerializer
{
    public static function supports(string $type, ?string $docblockType = null): bool
    {
        return $type === 'int';
    }

    public function serialize(FieldDefinition $definition, mixed $value): AttributeValue
    {
        if (!\is_int($value)) {
            throw new WrongTypeException($definition, 'int', $value);
        }

        return AttributeValue::create(['N' => (string) $value]);
    }

    public function deserialize(FieldDefinition $definition, AttributeValue $attributeValue): mixed
    {
        if (($value = $attributeValue->getN()) === null) {
            throw new MissingAttributeValueException($definition, 'N');
        }

        return (int) $value;
    }
}
