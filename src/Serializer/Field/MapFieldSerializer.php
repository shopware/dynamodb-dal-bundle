<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Serializer\Field;

use Shopware\DynamodbDalBundle\ArrayTypeParser;
use Shopware\DynamodbDalBundle\Definition\FieldDefinition;
use Shopware\DynamodbDalBundle\Exception\SerializerException;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;

/**
 * Serializes a PHP associative array to a DynamoDB Map (M) type.
 * Selected when the property has type "array" and @var array<K,V> (e.g. array<string, int>).
 *
 * Map values are encoded/decoded using the serializer for the field's value type (from @var or Field::valueType).
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
            throw SerializerException::wrongType(self::class, $definition, 'array', $value);
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
                $nestedPath = $valueDef->getName();
                if ($e instanceof SerializerException && ($prevPath = $e->getParameters()['nestedPath'] ?? null) !== null) {
                    $nestedPath .= '.' . $prevPath;
                }

                throw SerializerException::fieldSerializationFailed($valueDef->getSerializer()::class, $valueDef, $v, $e, $nestedPath);
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
            throw SerializerException::fieldAttributeValueMissing(self::class, $attributeValue, $definition, 'M');
        }

        $result = [];
        foreach ($map as $key => $nested) {
            if (!$nested instanceof AttributeValue) {
                throw SerializerException::fieldAttributeValueMissing(self::class, $attributeValue, $definition, 'M');
            }

            if ($nested->getNull() === true) {
                $result[$key] = null;

                continue;
            }

            try {
                $result[$key] = $valueDef->getSerializer()->deserialize($valueDef, $nested);
            } catch (\Throwable $e) {
                $nestedPath = $valueDef->getName();
                if ($e instanceof SerializerException && ($prevPath = $e->getParameters()['nestedPath'] ?? null) !== null) {
                    $nestedPath .= '.' . $prevPath;
                }

                throw SerializerException::fieldDeserializationFailed($valueDef->getSerializer()::class, $valueDef, $nested, $e, $nestedPath);
            }
        }

        return $result;
    }
}
