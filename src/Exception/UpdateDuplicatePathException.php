<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Exception;

use Shopware\DynamodbDalBundle\Definition\EntityDefinition;

/**
 * An update gives one path two values, as a field and the value of an action such as `setIfNotExists()`, or as
 * the values of two actions. The normalizer takes one value per path, so one of the two would be lost without a
 * word. Other overlapping paths, such as a field and an increment of it, reach DynamoDB, which refuses them.
 */
final class UpdateDuplicatePathException extends \RuntimeException implements DALException
{
    public function __construct(
        public readonly EntityDefinition $entityDefinition,
        public readonly string $path,
    ) {
        parent::__construct(\sprintf('Update of item "%s" writes path "%s" more than once', $entityDefinition->getName(), $path));
    }
}
