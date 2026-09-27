<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Exception;

use Shopware\DynamodbDalBundle\Definition\AttributeType;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;

/**
 * An expression uses a field as a type it is not stored as, such as `size()` of a number or `list_append()` to a map.
 */
final class AttributeTypeMismatchException extends \RuntimeException implements DALException
{
    /**
     * @param string $field - the path or operand, such as `size(tags)`
     * @param non-empty-list<AttributeType> $expectedTypes
     */
    public function __construct(
        public readonly EntityDefinition $entityDefinition,
        public readonly string $field,
        public readonly AttributeType $actualType,
        public readonly array $expectedTypes,
    ) {
        parent::__construct(\sprintf(
            '"%s" in item "%s" is of type %s, where %s%s is expected',
            $field,
            $entityDefinition->getName(),
            $actualType->value,
            \count($expectedTypes) > 1 ? 'one of ' : '',
            implode(', ', array_map(static fn (AttributeType $type): string => $type->value, $expectedTypes)),
        ));
    }
}
