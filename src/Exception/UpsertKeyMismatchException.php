<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Exception;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;

/**
 * An upsert's update and put would address two different rows, because the entity's normalizer gives the key other
 * values for a put than for a key, such as a key it composes from other fields for a put only. The update could then
 * miss a row the put finds, round after round, so the bundle refuses the upsert before it sends either.
 */
final class UpsertKeyMismatchException extends \RuntimeException implements DALException
{
    public function __construct(
        public readonly EntityDefinition $entityDefinition,
        public readonly AbstractEntity $entity,
    ) {
        parent::__construct(\sprintf(
            'Upsert of item "%s" addresses one row with its update and another with its put, as its normalizer gives the key other values for a put',
            $entityDefinition->getName(),
        ));
    }
}
