<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Exception;

use Shopware\DynamodbDalBundle\Definition\EntityDefinition;

/**
 * A field name that the entity does not declare — a typo, rather than a value to skip silently,
 * whether it was handed in to be written or to be filtered on.
 * Also thrown for a path the field's type cannot hold, such as an index into a map.
 *
 * It belongs to both groups it is thrown for: an {@see ExpressionException} where a filter, condition or update names
 * the field, and a {@see SerializationException} where a normalizer sets it on a put or a key.
 */
final class UnknownFieldException extends \RuntimeException implements ExpressionException, SerializationException
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
