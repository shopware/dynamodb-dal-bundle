<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Client\Input;

/**
 * How an {@see UpdateInput} keyed by an entity brings that entity up to date with what it wrote.
 * A lone update gets the stored item back from DynamoDB for any case but {@see self::None}.
 * An update in a transaction gets no item back, which is where the cases differ.
 */
enum Refresh
{
    /**
     * The entity gets every value the update wrote. A transaction reads the item back where it cannot know them
     * itself: for a nested path, or for an action, whose value DynamoDB computes.
     */
    case Full;

    /**
     * Like {@see self::Full}, but a transaction never reads the item back: it applies the fields written as a whole,
     * and leaves the entity as it was for nested paths and computed values.
     */
    case WithoutReadBack;

    /**
     * The entity is left as it is.
     */
    case None;
}
