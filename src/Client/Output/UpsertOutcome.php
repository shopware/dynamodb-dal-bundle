<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Client\Output;

use Shopware\DynamodbDalBundle\Client\Client;

/**
 * Which write of an upsert was stored, as {@see Client::upsert()} returns it.
 */
enum UpsertOutcome
{
    /**
     * No item was stored, so the entity was put.
     */
    case Created;

    /**
     * An item was stored, and took the update.
     */
    case Updated;
}
