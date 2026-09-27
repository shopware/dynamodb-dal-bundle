<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Expression\Fixtures;

use Shopware\DynamodbDalBundle\Definition\AttributeType;
use Shopware\DynamodbDalBundle\Definition\FieldDefinition;
use Shopware\DynamodbDalBundle\Exception\WrongTypeException;
use Shopware\DynamodbDalBundle\Serializer\Field\AbstractFieldSerializer;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;

/**
 * Writes a list of strings as a DynamoDB string set, as an application's own set type would be written, since the
 * bundle has none.
 *
 * @extends AbstractFieldSerializer<list<string>, 'array'>
 */
final class StringSetFieldSerializer extends AbstractFieldSerializer
{
    public static function supports(string $type, ?string $docblockType = null): bool
    {
        return false;
    }

    public function getAttributeType(FieldDefinition $definition): AttributeType
    {
        return AttributeType::StringSet;
    }

    public function serialize(FieldDefinition $definition, mixed $value): AttributeValue
    {
        if (!\is_array($value)) {
            throw new WrongTypeException($definition, 'list<string>', $value);
        }

        $strings = [];
        foreach ($value as $element) {
            $strings[] = \is_string($element) ? $element : throw new WrongTypeException($definition, 'list<string>', $value);
        }

        return AttributeValue::create(['SS' => $strings]);
    }

    public function deserialize(FieldDefinition $definition, AttributeValue $attributeValue): mixed
    {
        return array_values($attributeValue->getSs());
    }
}
