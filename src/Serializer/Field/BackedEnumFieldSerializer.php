<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Serializer\Field;

use Shopware\DynamodbDalBundle\Definition\FieldDefinition;
use Shopware\DynamodbDalBundle\Exception\MissingAttributeValueException;
use Shopware\DynamodbDalBundle\Exception\WrongTypeException;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;

/**
 * Serializes {@see \BackedEnum}.
 * Enums without any cases can not be serialized.
 * Enums missing a value or can not be deserialized will fallback to the first case available.
 *
 * @internal
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
            throw new WrongTypeException($definition, \BackedEnum::class, $value);
        }

        return AttributeValue::create(['S' => (string) $value->value]);
    }

    public function deserialize(FieldDefinition $definition, AttributeValue $attributeValue): mixed
    {
        if (($value = $attributeValue->getS()) === null) {
            throw new MissingAttributeValueException($definition, 'S');
        }

        try {
            return ($definition->getType())::from($value);
        } catch (\TypeError) {
            return ($definition->getType())::from((int) $value);
        }
    }
}
