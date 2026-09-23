<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Exception;

use Shopware\DynamodbDalBundle\Definition\FieldDefinition;

/**
 * The stored attribute does not carry the DynamoDB type the field's serializer reads it as — an "S"
 * where a list was expected, say, or an attribute that is not there at all.
 */
final class MissingAttributeValueException extends \RuntimeException implements DALException
{
    public function __construct(
        public readonly FieldDefinition $fieldDefinition,
        public readonly string $dynamoDbType,
    ) {
        parent::__construct(\sprintf(
            'Missing expected DynamoDB attribute value of type "%s" for field "%s" in item "%s"',
            $dynamoDbType,
            $fieldDefinition->getName(),
            $fieldDefinition->getEntityDefinition()->getName(),
        ));
    }
}
