<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Serializer\Field;

use Shopware\DynamodbDalBundle\Definition\FieldDefinition;
use Shopware\DynamodbDalBundle\Exception\MissingAttributeValueException;
use Shopware\DynamodbDalBundle\Exception\WrongTypeException;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;

/**
 * Serializes {@see \BackedEnum} to its backing value as a string (`S`), an int-backed one included.
 * Enums without any cases can not be serialized.
 * A stored value that is no case of the enum fails to deserialize, so a case can only be removed once no row holds it.
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

        $type = $definition->getType();

        try {
            return $type::from($value);
        } catch (\TypeError) {
            $int = filter_var($value, \FILTER_VALIDATE_INT);
            if ($int === false) {
                // Like PHP's `BackedEnum::from()`
                throw new \ValueError(\sprintf('"%s" is not a valid backing value for enum %s', $value, $type));
            }

            return $type::from($int);
        }
    }
}
