<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Exception;

use Shopware\DynamodbDalBundle\Definition\FieldDefinition;

/**
 * A criteria filter was given null as the value to compare against. DynamoDB has no null to compare
 * with: absence is what `exists`/`not(exists)` ask about.
 */
final class NullFilterValueException extends \RuntimeException implements DALException
{
    public function __construct(public readonly FieldDefinition $fieldDefinition)
    {
        parent::__construct(\sprintf(
            'Filter value for field "%s" in item "%s" must not be null.'
                . ' Use exists/not(exists) to filter for absence of an attribute.',
            $fieldDefinition->getName(),
            $fieldDefinition->getEntityDefinition()->getName(),
        ));
    }
}
