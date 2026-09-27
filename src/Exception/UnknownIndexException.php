<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Exception;

use Shopware\DynamodbDalBundle\Definition\EntityDefinition;

/**
 * A query names an index that the entity's `#[Table]` does not declare — a typo, or an index the definition is
 * missing. Without its key schema, neither the key condition nor a page token could be checked against it.
 */
final class UnknownIndexException extends \RuntimeException implements DALException
{
    public function __construct(
        public readonly EntityDefinition $entityDefinition,
        public readonly string $index,
    ) {
        parent::__construct(\sprintf(
            'Unknown index "%s" of item "%s"',
            $index,
            $entityDefinition->getName(),
        ));
    }
}
