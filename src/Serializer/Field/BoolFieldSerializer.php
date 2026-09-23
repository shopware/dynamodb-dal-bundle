<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Serializer\Field;

use Shopware\DynamodbDalBundle\Definition\FieldDefinition;
use Shopware\DynamodbDalBundle\Exception\SerializerException;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;

/**
 * @extends AbstractFieldSerializer<bool, 'bool'>
 */
class BoolFieldSerializer extends AbstractFieldSerializer
{
    public static function supports(string $type, ?string $docblockType = null): bool
    {
        return $type === 'bool';
    }

    public function serialize(FieldDefinition $definition, mixed $value): AttributeValue
    {
        if (!\is_bool($value)) {
            throw SerializerException::wrongType(self::class, $definition, 'bool', $value);
        }

        return AttributeValue::create(['BOOL' => $value]);
    }

    public function deserialize(FieldDefinition $definition, AttributeValue $attributeValue): mixed
    {
        if (($value = $attributeValue->getBool()) === null) {
            throw SerializerException::fieldAttributeValueMissing(self::class, $attributeValue, $definition, 'BOOL');
        }

        return $value;
    }
}
