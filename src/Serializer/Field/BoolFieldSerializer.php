<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Serializer\Field;

use Shopware\DynamodbDalBundle\Definition\FieldDefinition;
use Shopware\DynamodbDalBundle\Exception\MissingAttributeValueException;
use Shopware\DynamodbDalBundle\Exception\WrongTypeException;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;

/**
 * @internal
 *
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
            throw new WrongTypeException($definition, 'bool', $value);
        }

        return AttributeValue::create(['BOOL' => $value]);
    }

    public function deserialize(FieldDefinition $definition, AttributeValue $attributeValue): mixed
    {
        if (($value = $attributeValue->getBool()) === null) {
            throw new MissingAttributeValueException($definition, 'BOOL');
        }

        return $value;
    }
}
