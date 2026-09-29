<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Definition;

use Shopware\DynamodbDalBundle\Serializer\Field\AbstractFieldSerializer;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;

/**
 * The DynamoDB type a field is stored as, declared by {@see AbstractFieldSerializer::getAttributeType()}.
 * Expressions check against it to fail early on what DynamoDB would reject or never match.
 */
enum AttributeType: string
{
    case String = 'S';
    case Number = 'N';
    case Binary = 'B';
    case Boolean = 'BOOL';
    case List = 'L';
    case Map = 'M';
    case StringSet = 'SS';
    case NumberSet = 'NS';
    case BinarySet = 'BS';

    /**
     * The type a serialized value is stored as, `null` where the value does not tell it: `NULL`, which has no case here,
     * and an empty map, list or set, which AsyncAws cannot tell apart.
     */
    public static function tryFromAttributeValue(AttributeValue $value): ?self
    {
        return match (true) {
            $value->getS() !== null => self::String,
            $value->getN() !== null => self::Number,
            $value->getB() !== null => self::Binary,
            $value->getBool() !== null => self::Boolean,
            $value->getSs() !== [] => self::StringSet,
            $value->getNs() !== [] => self::NumberSet,
            $value->getBs() !== [] => self::BinarySet,
            $value->getL() !== [] => self::List,
            $value->getM() !== [] => self::Map,
            default => null,
        };
    }
}
