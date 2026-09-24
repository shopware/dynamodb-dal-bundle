<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\Entity;

use AsyncAws\DynamoDb\ValueObject\AttributeValue;
use Shopware\DynamodbDalBundle\Definition\FieldDefinition;
use Shopware\DynamodbDalBundle\Exception\MissingAttributeValueException;
use Shopware\DynamodbDalBundle\Exception\WrongTypeException;
use Shopware\DynamodbDalBundle\Serializer\Field\AbstractFieldSerializer;

/**
 * A field serializer of the application's own for a `JsonSerializable` type, which the JSON serializer
 * claims as well.
 *
 * @extends AbstractFieldSerializer<Address, class-string<Address>>
 */
class AddressFieldSerializer extends AbstractFieldSerializer
{
    public static function supports(string $type, ?string $docblockType = null): bool
    {
        return $type === Address::class;
    }

    public function serialize(FieldDefinition $definition, mixed $value): AttributeValue
    {
        if (!$value instanceof Address) {
            throw new WrongTypeException($definition, Address::class, $value);
        }

        return AttributeValue::create(['S' => \sprintf('%s, %s', $value->street, $value->city)]);
    }

    public function deserialize(FieldDefinition $definition, AttributeValue $attributeValue): Address
    {
        $value = $attributeValue->getS() ?? throw new MissingAttributeValueException($definition, 'S');

        [$street, $city] = explode(', ', $value, 2);

        return new Address($street, $city);
    }
}
