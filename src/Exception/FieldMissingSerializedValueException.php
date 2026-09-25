<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Exception;

use Shopware\DynamodbDalBundle\Definition\FieldDefinition;

/**
 * A field that may not be null has no serialized value to write: it was handed in as null with no
 * default to fall back on (an update, which removes a field given as null, never falls back on one), or it
 * serialized to nothing where a value was required, as a primary or index key attribute always is.
 * Either way nothing reached the item, and the normalizer — which runs before this and would have filled it — did not.
 */
final class FieldMissingSerializedValueException extends \RuntimeException implements DALException
{
    public function __construct(public readonly FieldDefinition $fieldDefinition)
    {
        parent::__construct(\sprintf(
            'Missing required value for field "%s" in item "%s" during serialization',
            $fieldDefinition->getName(),
            $fieldDefinition->getEntityDefinition()->getName(),
        ));
    }
}
