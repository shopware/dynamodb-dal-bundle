<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Serializer\Field;

use Shopware\DynamodbDalBundle\ArrayTypeParser;
use Shopware\DynamodbDalBundle\Definition\FieldDefinition;
use Shopware\DynamodbDalBundle\Exception\SerializerException;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;

/**
 * Serializes a PHP sequential array to a DynamoDB List (L) type.
 * Selected when the property has type "array" and @var list<T> (e.g. list<string>).
 *
 * List values are encoded/decoded using the serializer for the field's value type (from @var or Field::valueType).
 *
 * @extends AbstractFieldSerializer<array<int, mixed>, 'array'>
 */
class ListFieldSerializer extends AbstractFieldSerializer
{
    public static function supports(string $type, ?string $docblockType = null): bool
    {
        return $type === 'array' && $docblockType !== null && ArrayTypeParser::isListType($docblockType);
    }

    public function serialize(FieldDefinition $definition, mixed $value): AttributeValue
    {
        $valueDef = $definition->getValueFieldDefinition();
        if ($valueDef === null) {
            throw new \LogicException('List field "' . $definition->getName() . '" must have value type from @var list<T> or Field::valueType');
        }

        if (!\is_array($value)) {
            throw SerializerException::wrongType(self::class, $definition, 'array', $value);
        }

        $l = [];
        foreach ($value as $v) {
            if ($v === null) {
                $l[] = AttributeValue::create(['NULL' => true]);

                continue;
            }

            try {
                $l[] = $valueDef->getSerializer()->serialize($valueDef, $v);
            } catch (\Throwable $e) {
                $nestedPath = $valueDef->getName();
                if ($e instanceof SerializerException && ($prevPath = $e->getParameters()['nestedPath'] ?? null) !== null) {
                    $nestedPath .= '.' . $prevPath;
                }

                throw SerializerException::fieldSerializationFailed($valueDef->getSerializer()::class, $valueDef, $v, $e, $nestedPath);
            }
        }

        return AttributeValue::create(['L' => $l]);
    }

    public function deserialize(FieldDefinition $definition, AttributeValue $attributeValue): mixed
    {
        $valueDef = $definition->getValueFieldDefinition();
        if ($valueDef === null) {
            throw new \LogicException('List field "' . $definition->getName() . '" must have value type from @var list<T> or Field::valueType');
        }

        $list = $attributeValue->getL();
        if ($list === [] && !isset($attributeValue->requestBody()['L'])) {
            throw SerializerException::fieldAttributeValueMissing(self::class, $attributeValue, $definition, 'L');
        }

        $result = [];
        foreach ($list as $nested) {
            if (!$nested instanceof AttributeValue) {
                throw SerializerException::fieldAttributeValueMissing(self::class, $attributeValue, $definition, 'L');
            }

            if ($nested->getNull() === true) {
                $result[] = null;

                continue;
            }

            try {
                $result[] = $valueDef->getSerializer()->deserialize($valueDef, $nested);
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
