<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Exception;

use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use AsyncAws\DynamoDb\Exception\ConditionalCheckFailedException;

/**
 * An upsert gave up because other writers created and deleted its item between its update and its put, in every round
 * it sent them. Neither write is stored.
 * The upsert's own condition did not fail, which a {@see ConditionalCheckFailedException} would say, so the upsert can be sent again.
 */
final class UpsertContentionException extends \RuntimeException implements DALException
{
    /**
     * @param ConditionalCheckFailedException $previous - the put's failure in the last round
     */
    public function __construct(
        public readonly EntityDefinition $entityDefinition,
        int $rounds,
        ConditionalCheckFailedException $previous,
    ) {
        parent::__construct(\sprintf(
            'Upsert of item "%s" gave up after %d rounds, in each of which another writer created or deleted the item',
            $entityDefinition->getName(),
            $rounds,
        ), 0, $previous);
    }
}
