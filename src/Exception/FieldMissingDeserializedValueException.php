<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Exception;

use Shopware\DynamodbDalBundle\Definition\FieldDefinition;

/**
 * A field that may not be null has no value once the stored item has been deserialized and
 * denormalized — an item written before the field existed, most of the time.
 */
final class FieldMissingDeserializedValueException extends \RuntimeException implements DALException
{
    public function __construct(public readonly FieldDefinition $fieldDefinition)
    {
        parent::__construct(\sprintf(
            'Missing required value for field "%s" in item "%s" after deserialization and denormalization.'
                . ' Consider providing a default value, allowing null or migrate in the denormalization step.',
            $fieldDefinition->getName(),
            $fieldDefinition->getEntityDefinition()->getName(),
        ));
    }
}
