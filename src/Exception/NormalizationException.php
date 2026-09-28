<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Exception;

use Shopware\DynamodbDalBundle\Definition\EntityDefinition;

/**
 * The entity's normalizer failed on the fields of a put, an update or a key, and threw something other than a
 * {@see DALException}. Nothing is sent. `getPrevious()` holds what it threw.
 */
final class NormalizationException extends \RuntimeException implements SerializationException
{
    public function __construct(
        public readonly EntityDefinition $entityDefinition,
        \Throwable $previous,
    ) {
        parent::__construct(\sprintf('The normalizer of item "%s" could not normalize its fields', $entityDefinition->getName()), 0, $previous);
    }
}
