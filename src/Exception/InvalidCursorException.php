<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Exception;

/**
 * A pagination token could not be resumed: it is not one the DAL produced, or it belongs to a different
 * query. Tokens usually arrive from a URL, so this is typically a client error rather than a bug.
 */
final class InvalidCursorException extends \InvalidArgumentException implements DALException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct(\sprintf('Invalid pagination cursor: %s.', $reason));
    }
}
