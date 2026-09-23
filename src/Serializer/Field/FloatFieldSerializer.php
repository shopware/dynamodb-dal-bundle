<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Serializer\Field;

use Shopware\DynamodbDalBundle\Definition\FieldDefinition;
use Shopware\DynamodbDalBundle\Exception\MissingAttributeValueException;
use Shopware\DynamodbDalBundle\Exception\WrongTypeException;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;

/**
 * @internal
 *
 * @extends AbstractFieldSerializer<float, 'float'>
 */
class FloatFieldSerializer extends AbstractFieldSerializer
{
    public static function supports(string $type, ?string $docblockType = null): bool
    {
        return $type === 'float';
    }

    public function serialize(FieldDefinition $definition, mixed $value): AttributeValue
    {
        if (!\is_float($value) && !\is_int($value)) {
            throw new WrongTypeException($definition, 'float', $value);
        }

        return AttributeValue::create(['N' => json_encode($value, \JSON_THROW_ON_ERROR)]);
    }

    public function deserialize(FieldDefinition $definition, AttributeValue $attributeValue): mixed
    {
        if (($value = $attributeValue->getN()) === null) {
            throw new MissingAttributeValueException($definition, 'N');
        }

        return (float) $value;
    }
}
