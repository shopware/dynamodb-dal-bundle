<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Serializer\Field;

use Shopware\DynamodbDalBundle\Definition\FieldDefinition;
use Shopware\DynamodbDalBundle\Exception\MissingAttributeValueException;
use Shopware\DynamodbDalBundle\Exception\WrongTypeException;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;

/**
 * Can not support {@see \DateTimeInterface} as it has no common method to construct an object.
 *
 * @internal
 *
 * @extends AbstractFieldSerializer<\DateTimeImmutable|\DateTime, class-string<\DateTimeImmutable|\DateTime>>
 */
class DateTimeFieldSerializer extends AbstractFieldSerializer
{
    public static function supports(string $type, ?string $docblockType = null): bool
    {
        return $type === \DateTimeImmutable::class
            || $type === \DateTime::class;
    }

    public function serialize(FieldDefinition $definition, mixed $value): AttributeValue
    {
        if (!$value instanceof \DateTimeInterface) {
            throw new WrongTypeException($definition, \DateTimeInterface::class, $value);
        }

        return AttributeValue::create(['N' => (string) $value->getTimestamp()]);
    }

    public function deserialize(FieldDefinition $definition, AttributeValue $attributeValue): mixed
    {
        if (($value = $attributeValue->getN()) !== null) {
            /** @var \DateTimeImmutable|\DateTime $dateTime */
            $dateTime = new ($definition->getType())('@' . $value);

            return $dateTime;
        }

        if (($value = $attributeValue->getS()) !== null) {
            /** @var \DateTimeImmutable|\DateTime $dateTime */
            $dateTime = new ($definition->getType())($value);

            return $dateTime;
        }

        throw new MissingAttributeValueException($definition, 'N');
    }
}
