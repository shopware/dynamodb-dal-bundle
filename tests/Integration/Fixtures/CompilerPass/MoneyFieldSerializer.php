<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\CompilerPass;

use AsyncAws\DynamoDb\ValueObject\AttributeValue;
use Shopware\DynamodbDalBundle\Definition\FieldDefinition;
use Shopware\DynamodbDalBundle\Exception\SerializerException;
use Shopware\DynamodbDalBundle\Serializer\Field\AbstractFieldSerializer;

/**
 * The kind of field serializer an application contributes for a type of its own.
 *
 * @extends AbstractFieldSerializer<Money, class-string<Money>>
 */
class MoneyFieldSerializer extends AbstractFieldSerializer
{
    public static function supports(string $type, ?string $docblockType = null): bool
    {
        return $type === Money::class;
    }

    public function serialize(FieldDefinition $definition, mixed $value): AttributeValue
    {
        if (!$value instanceof Money) {
            throw SerializerException::wrongType(self::class, $definition, Money::class, $value);
        }

        return AttributeValue::create(['S' => \sprintf('%d %s', $value->cents, $value->currency)]);
    }

    public function deserialize(FieldDefinition $definition, AttributeValue $attributeValue): mixed
    {
        if (($value = $attributeValue->getS()) === null) {
            throw SerializerException::fieldAttributeValueMissing(self::class, $attributeValue, $definition, 'S');
        }

        [$cents, $currency] = explode(' ', $value, 2);

        return new Money((int) $cents, $currency);
    }
}
