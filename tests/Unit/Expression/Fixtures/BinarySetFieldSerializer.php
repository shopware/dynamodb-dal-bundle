<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Expression\Fixtures;

use Shopware\DynamodbDalBundle\Definition\AttributeType;
use Shopware\DynamodbDalBundle\Definition\FieldDefinition;
use Shopware\DynamodbDalBundle\Exception\WrongTypeException;
use Shopware\DynamodbDalBundle\Serializer\Field\AbstractFieldSerializer;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;

/**
 * Writes a list of byte strings as a DynamoDB binary set, as an application's own set type would be written, since the
 * bundle has none.
 *
 * @extends AbstractFieldSerializer<list<string>, 'array'>
 */
final class BinarySetFieldSerializer extends AbstractFieldSerializer
{
    public static function supports(string $type, ?string $docblockType = null): bool
    {
        return false;
    }

    public static function getAttributeType(): AttributeType
    {
        return AttributeType::BinarySet;
    }

    public function serialize(FieldDefinition $definition, mixed $value): AttributeValue
    {
        if (!\is_array($value)) {
            throw new WrongTypeException($definition, 'list<string>', $value);
        }

        $bytes = [];
        foreach ($value as $element) {
            $bytes[] = \is_string($element) ? $element : throw new WrongTypeException($definition, 'list<string>', $value);
        }

        return AttributeValue::create(['BS' => $bytes]);
    }

    public function deserialize(FieldDefinition $definition, AttributeValue $attributeValue): mixed
    {
        return array_values($attributeValue->getBs());
    }
}
