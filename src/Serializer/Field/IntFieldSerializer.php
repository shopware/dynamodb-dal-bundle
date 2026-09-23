<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Serializer\Field;

use Shopware\DynamodbDalBundle\Definition\FieldDefinition;
use Shopware\DynamodbDalBundle\Exception\SerializerException;
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
            throw SerializerException::wrongType(self::class, $definition, 'int', $value);
        }

        return AttributeValue::create(['N' => (string) $value]);
    }

    public function deserialize(FieldDefinition $definition, AttributeValue $attributeValue): mixed
    {
        if (($value = $attributeValue->getN()) === null) {
            throw SerializerException::fieldAttributeValueMissing(self::class, $attributeValue, $definition, 'N');
        }

        return (int) $value;
    }
}
