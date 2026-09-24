<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Exception;

use Shopware\DynamodbDalBundle\Definition\EntityDefinition;

/**
 * An update that writes nothing: it names no field and no action, or only actions that compiled to
 * nothing. A transaction refuses an update without an update expression, and a lone `UpdateItem`
 * would pass as a write that changed nothing.
 */
final class UpdateEmptyException extends \RuntimeException implements DALException
{
    public function __construct(public readonly EntityDefinition $entityDefinition)
    {
        parent::__construct(\sprintf('Update of item "%s" has nothing to write', $entityDefinition->getName()));
    }
}
