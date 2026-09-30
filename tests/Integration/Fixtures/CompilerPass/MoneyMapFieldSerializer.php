<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\CompilerPass;

use AsyncAws\DynamoDb\ValueObject\AttributeValue;
use Shopware\DynamodbDalBundle\Definition\AttributeType;
use Shopware\DynamodbDalBundle\Definition\FieldDefinition;
use Shopware\DynamodbDalBundle\Exception\MissingAttributeValueException;
use Shopware\DynamodbDalBundle\Exception\WrongTypeException;
use Shopware\DynamodbDalBundle\Serializer\Field\AbstractFieldSerializer;

/**
 * Stores {@see Money} as a map of its properties, the second way next to {@see MoneyFieldSerializer}'s string, for the
 * fields that ask for a map.
 *
 * @extends AbstractFieldSerializer<Money, class-string<Money>>
 */
class MoneyMapFieldSerializer extends AbstractFieldSerializer
{
    public static function supports(string $type, ?string $docblockType = null): bool
    {
        return $type === Money::class;
    }

    public static function getAttributeType(): AttributeType
    {
        return AttributeType::Map;
    }

    public function serialize(FieldDefinition $definition, mixed $value): AttributeValue
    {
        if (!$value instanceof Money) {
            throw new WrongTypeException($definition, Money::class, $value);
        }

        return AttributeValue::create(['M' => [
            'cents' => AttributeValue::create(['N' => (string) $value->cents]),
            'currency' => AttributeValue::create(['S' => $value->currency]),
        ]]);
    }

    public function deserialize(FieldDefinition $definition, AttributeValue $attributeValue): Money
    {
        $map = $attributeValue->getM();

        return new Money(
            (int) (($map['cents'] ?? null)?->getN() ?? throw new MissingAttributeValueException($definition, 'N')),
            ($map['currency'] ?? null)?->getS() ?? throw new MissingAttributeValueException($definition, 'S'),
        );
    }
}
