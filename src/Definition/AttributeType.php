<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Definition;

use Shopware\DynamodbDalBundle\Serializer\Field\AbstractFieldSerializer;

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
}
