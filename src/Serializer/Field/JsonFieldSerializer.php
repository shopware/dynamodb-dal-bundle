<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Serializer\Field;

use Shopware\DynamodbDalBundle\Definition\FieldDefinition;
use Shopware\DynamodbDalBundle\Exception\MissingAttributeValueException;
use Shopware\DynamodbDalBundle\Exception\WrongTypeException;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;
use Symfony\Component\Uid\AbstractUid;

/**
 * Serializes a JSON-compatible array or JsonSerializable value object to/from a single JSON string field.
 *
 * Deserialization returns the decoded array so the entity normalizer can convert it into the target struct.
 *
 * @extends AbstractFieldSerializer<array<string, mixed>|\JsonSerializable, 'array'|class-string>
 */
class JsonFieldSerializer extends AbstractFieldSerializer
{
    public static function supports(string $type, ?string $docblockType = null): bool
    {
        if ($docblockType !== null) {
            return false;
        }

        return $type === 'array' || self::isSupportedJsonSerializable($type);
    }

    public function serialize(FieldDefinition $definition, mixed $value): AttributeValue
    {
        if ($value instanceof \JsonSerializable) {
            $value = $value->jsonSerialize();
        }

        if (!\is_array($value)) {
            throw new WrongTypeException($definition, 'array|\JsonSerializable', $value);
        }

        return AttributeValue::create(['S' => json_encode($value, \JSON_THROW_ON_ERROR)]);
    }

    public function deserialize(FieldDefinition $definition, AttributeValue $attributeValue): mixed
    {
        $s = $attributeValue->getS();
        if ($s === null) {
            throw new MissingAttributeValueException($definition, 'S');
        }

        $decoded = json_decode($s, true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            throw new WrongTypeException($definition, 'array', $decoded);
        }

        return $decoded;
    }

    private static function isSupportedJsonSerializable(string $type): bool
    {
        return is_a($type, \JsonSerializable::class, true)
            && !is_a($type, AbstractUid::class, true)
            && !is_a($type, \DateTimeInterface::class, true)
            && !is_a($type, \BackedEnum::class, true);
    }
}
