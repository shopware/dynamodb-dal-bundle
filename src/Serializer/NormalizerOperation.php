<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Serializer;

/**
 * Why a normalizer runs, as {@see NormalizerContext::$operation} tells it.
 * It names what the fields are, not which call brought them.
 * `denormalize()` sees
 * - `Read` for every item DynamoDB returns, including the updated item a lone update keyed by an entity asks for;
 * - `Put` or `Update` when the fields a write sent are applied back onto its entity because no item came back:
 *   after a put, and after an update in a transaction.
 *
 * MUST NOT be handled exhaustively, new cases may be added.
 */
enum NormalizerOperation
{
    /**
     * A whole item is written. Every field is present, `null` where the entity's property is not initialized.
     * Whether an item with that key is stored already is not known.
     */
    case Put;

    /**
     * An update writes the fields present, paths into an attribute included, and removes those that are `null`.
     * The value an update action stores as given is present under its path too: the one `setIfNotExists()` offers,
     * or the elements `append()` adds and not the whole list. There, `null` writes nothing.
     * The item exists, since every update is conditioned on it.
     */
    case Update;

    /**
     * An item's key, to look it up, delete it or update it by.
     * Only the key fields are present.
     * A pagination cursor keeps the key DynamoDB returned as it is, without the normalizer.
     */
    case Key;

    /**
     * An item read from DynamoDB.
     * Every field is present.
     * One the row lacks is `null`, or its default where it is not nullable and has one.
     */
    case Read;
}
