<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Serializer\Field;

use Shopware\DynamodbDalBundle\ArrayTypeParser;
use Shopware\DynamodbDalBundle\Definition\FieldDefinition;
use Shopware\DynamodbDalBundle\Exception\FieldDeserializationException;
use Shopware\DynamodbDalBundle\Exception\FieldSerializationException;
use Shopware\DynamodbDalBundle\Exception\MissingAttributeValueException;
use Shopware\DynamodbDalBundle\Exception\WrongTypeException;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;

/**
 * Serializes a PHP associative array to a DynamoDB Map (M) type.
 * Selected when the property has type "array" and @var array<K,V> (e.g. array<string, int>).
 *
 * Map values are encoded/decoded using the serializer for the field's value type (from @var or Field::valueType).
 *
 * @internal
 *
 * @extends AbstractFieldSerializer<array<string, mixed>, 'array'>
 */
class MapFieldSerializer extends AbstractFieldSerializer
{
    public static function supports(string $type, ?string $docblockType = null): bool
    {
        return $type === 'array' && $docblockType !== null && ArrayTypeParser::isMapType($docblockType);
    }

    public function serialize(FieldDefinition $definition, mixed $value): AttributeValue
    {
        $valueDef = $definition->getValueFieldDefinition();
        if ($valueDef === null) {
            throw new \LogicException('Map field "' . $definition->getName() . '" must have valueType set so values can be serialized');
        }

        if (!\is_array($value)) {
            throw new WrongTypeException($definition, 'array', $value);
        }

        $m = [];
        foreach ($value as $k => $v) {
            $key = (string) $k;
            if ($v === null) {
                $m[$key] = AttributeValue::create(['NULL' => true]);

                continue;
            }

            try {
                $m[$key] = $valueDef->getSerializer()->serialize($valueDef, $v);
            } catch (\Throwable $e) {
                throw new FieldSerializationException($definition, $e, self::elementPath('.' . $key, $valueDef, $e));
            }
        }

        return AttributeValue::create(['M' => $m]);
    }

    public function deserialize(FieldDefinition $definition, AttributeValue $attributeValue): mixed
    {
        $valueDef = $definition->getValueFieldDefinition();
        if ($valueDef === null) {
            throw new \LogicException('Map field "' . $definition->getName() . '" must have valueType set so values can be deserialized');
        }

        $map = $attributeValue->getM();
        if ($map === [] && !isset($attributeValue->requestBody()['M'])) {
            throw new MissingAttributeValueException($definition, 'M');
        }

        $result = [];
        foreach ($map as $key => $nested) {
            if (!$nested instanceof AttributeValue) {
                throw new MissingAttributeValueException($definition, 'M');
            }

            if ($nested->getNull() === true) {
                $result[$key] = null;

                continue;
            }

            try {
                $result[$key] = $valueDef->getSerializer()->deserialize($valueDef, $nested);
            } catch (\Throwable $e) {
                throw new FieldDeserializationException($definition, $e, self::elementPath('.' . $key, $valueDef, $e));
            }
        }

        return $result;
    }
}
