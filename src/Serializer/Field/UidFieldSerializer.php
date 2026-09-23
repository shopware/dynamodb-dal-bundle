<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Serializer\Field;

use Shopware\DynamodbDalBundle\Definition\FieldDefinition;
use Shopware\DynamodbDalBundle\Exception\MissingAttributeValueException;
use Shopware\DynamodbDalBundle\Exception\WrongTypeException;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;
use Symfony\Component\Uid\AbstractUid;

/**
 * @internal
 *
 * @extends AbstractFieldSerializer<AbstractUid, class-string<AbstractUid>>
 */
class UidFieldSerializer extends AbstractFieldSerializer
{
    public static function supports(string $type, ?string $docblockType = null): bool
    {
        return is_subclass_of($type, AbstractUid::class, true);
    }

    public function serialize(FieldDefinition $definition, mixed $value): AttributeValue
    {
        if (!$value instanceof AbstractUid) {
            throw new WrongTypeException($definition, AbstractUid::class, $value);
        }

        return AttributeValue::create(['S' => $value->toString()]);
    }

    public function deserialize(FieldDefinition $definition, AttributeValue $attributeValue): mixed
    {
        if (($value = $attributeValue->getS()) === null) {
            throw new MissingAttributeValueException($definition, 'S');
        }

        return ($definition->getType())::fromString($value);
    }
}
