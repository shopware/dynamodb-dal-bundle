<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Expression\Fixtures;

use Shopware\DynamodbDalBundle\Definition\FieldDefinition;
use Shopware\DynamodbDalBundle\Exception\MissingAttributeValueException;
use Shopware\DynamodbDalBundle\Exception\WrongTypeException;
use Shopware\DynamodbDalBundle\Serializer\Field\AbstractFieldSerializer;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;

/**
 * Writes a string, but declares no attribute type, as a serializer of your own may. Every check against the stored
 * type then passes, and DynamoDB decides.
 *
 * @extends AbstractFieldSerializer<string, string>
 */
final class UntypedFieldSerializer extends AbstractFieldSerializer
{
    public static function supports(string $type, ?string $docblockType = null): bool
    {
        return false;
    }

    public function serialize(FieldDefinition $definition, mixed $value): AttributeValue
    {
        if (!\is_string($value)) {
            throw new WrongTypeException($definition, 'string', $value);
        }

        return AttributeValue::create(['S' => $value]);
    }

    public function deserialize(FieldDefinition $definition, AttributeValue $attributeValue): mixed
    {
        return $attributeValue->getS() ?? throw new MissingAttributeValueException($definition, 'S');
    }
}
