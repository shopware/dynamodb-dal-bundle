<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Exception;

use Shopware\DynamodbDalBundle\Definition\FieldDefinition;

/**
 * An expression was given null as an operand, such as the value a filter compares against or the value an update
 * adds or deletes. DynamoDB has no null operand: absence is what `exists`/`not(exists)` ask about, and what
 * `Update::remove()` leaves.
 */
final class NullOperandException extends \RuntimeException implements DALException
{
    public function __construct(public readonly FieldDefinition $fieldDefinition)
    {
        parent::__construct(\sprintf(
            'Operand for field "%s" in item "%s" must not be null.'
                . ' Use exists/not(exists) to filter for absence of an attribute, or Update::remove() to remove one.',
            $fieldDefinition->getName(),
            $fieldDefinition->getEntityDefinition()->getName(),
        ));
    }
}
