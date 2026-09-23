<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Exception;

/**
 * Nothing is registered under the name, table or entity class that was looked up — usually an entity
 * missing from the bundle's `entities` configuration.
 */
final class UnknownEntityDefinitionException extends \RuntimeException implements DALException
{
    public function __construct(public readonly string $identifier)
    {
        parent::__construct(\sprintf('No entity definition is registered for "%s"', $identifier));
    }
}
