<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Exception;

use Shopware\DynamodbDalBundle\Definition\EntityDefinition;

/**
 * The entity's normalizer failed on the fields of a row read, or of a write stored, and threw something other than a
 * {@see DALException}. `getPrevious()` holds what it threw.
 */
final class DenormalizationException extends \RuntimeException implements DeserializationException
{
    public function __construct(
        public readonly EntityDefinition $entityDefinition,
        \Throwable $previous,
    ) {
        parent::__construct(\sprintf('The normalizer of item "%s" could not denormalize its fields', $entityDefinition->getName()), 0, $previous);
    }
}
