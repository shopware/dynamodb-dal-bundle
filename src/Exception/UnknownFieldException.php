<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Exception;

use Shopware\DynamodbDalBundle\Definition\EntityDefinition;

/**
 * A field name that the entity does not declare — a typo, rather than a value to skip silently,
 * whether it was handed in to be written or to be filtered on.
 * Also thrown for a path the field's type cannot hold, such as an index into a map.
 */
final class UnknownFieldException extends \RuntimeException implements ExpressionException
{
    public function __construct(
        public readonly EntityDefinition $entityDefinition,
        public readonly string $field,
    ) {
        parent::__construct(\sprintf(
            'Unknown field "%s" in item "%s"',
            $field,
            $entityDefinition->getName(),
        ));
    }
}
