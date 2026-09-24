<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\Entity;

use AsyncAws\DynamoDb\ValueObject\AttributeValue;
use Shopware\DynamodbDalBundle\Definition\FieldDefinition;
use Shopware\DynamodbDalBundle\Exception\MissingAttributeValueException;
use Shopware\DynamodbDalBundle\Exception\WrongTypeException;
use Shopware\DynamodbDalBundle\Serializer\Field\AbstractFieldSerializer;

/**
 * Writes a {@see StringSet} as a DynamoDB string set, the application's own set type. An empty set is
 * written as it is, although DynamoDB refuses one, so that refusal can be asked of DynamoDB.
 *
 * @extends AbstractFieldSerializer<StringSet, class-string<StringSet>>
 */
class StringSetFieldSerializer extends AbstractFieldSerializer
{
    public static function supports(string $type, ?string $docblockType = null): bool
    {
        return $type === StringSet::class;
    }

    public function serialize(FieldDefinition $definition, mixed $value): AttributeValue
    {
        if (!$value instanceof StringSet) {
            throw new WrongTypeException($definition, StringSet::class, $value);
        }

        return AttributeValue::create(['SS' => $value->values]);
    }

    public function deserialize(FieldDefinition $definition, AttributeValue $attributeValue): StringSet
    {
        // DynamoDB stores no empty set, so an empty one here means the attribute is no string set.
        $values = $attributeValue->getSs();
        if ($values === []) {
            throw new MissingAttributeValueException($definition, 'SS');
        }

        return new StringSet(...$values);
    }
}
