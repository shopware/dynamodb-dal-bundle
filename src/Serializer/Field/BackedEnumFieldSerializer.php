<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Serializer\Field;

use Shopware\DynamodbDalBundle\Definition\FieldDefinition;
use Shopware\DynamodbDalBundle\Exception\SerializerException;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;

/**
 * Serializes {@see \BackedEnum}.
 * Enums without any cases can not be serialized.
 * Enums missing a value or can not be deserialized will fallback to the first case available.
 *
 * @extends AbstractFieldSerializer<\BackedEnum, class-string<\BackedEnum>>
 */
class BackedEnumFieldSerializer extends AbstractFieldSerializer
{
    public static function supports(string $type, ?string $docblockType = null): bool
    {
        if (!is_subclass_of($type, \BackedEnum::class, true)) {
            return false;
        }

        if ($type::cases() === []) {
            throw new \LogicException("Enum {$type} has no cases and can not be serialized");
        }

        return true;
    }

    public function serialize(FieldDefinition $definition, mixed $value): AttributeValue
    {
        if (!$value instanceof \BackedEnum) {
            throw SerializerException::wrongType(self::class, $definition, \BackedEnum::class, $value);
        }

        return AttributeValue::create(['S' => (string) $value->value]);
    }

    public function deserialize(FieldDefinition $definition, AttributeValue $attributeValue): mixed
    {
        if (($value = $attributeValue->getS()) === null) {
            throw SerializerException::fieldAttributeValueMissing(self::class, $attributeValue, $definition, 'S');
        }

        try {
            return ($definition->getType())::from($value);
        } catch (\TypeError) {
            return ($definition->getType())::from((int) $value);
        }
    }
}
