<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Exception;

use Shopware\DynamodbDalBundle\Client\Client;
use Shopware\DynamodbDalBundle\Client\Output\UpsertOutcome;

/**
 * A write is stored, but an entity it wrote could not be brought up to date: its stored row does not deserialize,
 * reading it back failed, or its normalizer failed on what was written. `getPrevious()` holds that failure.
 *
 * Don't retry the write. It went through, and a retry repeats it, so an `increment()` counts twice.
 * Read the entity again instead, once the cause is gone.
 *
 * It is a {@see DeserializationException}, the group a caller catches for a stored row that does not fit its entity:
 * that is what it is thrown for most of the time, and whatever the cause, the caller handles it the same way.
 */
final class EntityOutOfSyncException extends \RuntimeException implements DeserializationException
{
    /**
     * @param ?UpsertOutcome $upsertOutcome - which write of an upsert is stored, as {@see Client::upsert()} would have returned it; `null` for any other write
     */
    public function __construct(
        \Throwable $previous,
        public readonly ?UpsertOutcome $upsertOutcome = null,
    ) {
        parent::__construct(\sprintf(
            'The write is stored, but not every entity it wrote could be brought up to date: %s',
            $previous->getMessage(),
        ), 0, $previous);
    }
}
