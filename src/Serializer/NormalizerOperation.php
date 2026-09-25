<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Serializer;

/**
 * Why a normalizer runs, as {@see NormalizerContext::$operation} tells it. It names what the fields are, not
 * which call brought them: `denormalize()` sees `Read` for every item DynamoDB returns, the updated item a lone
 * update keyed by an entity asks for included, and `Put` or `Update` only when the fields a write sent are
 * applied back onto its entity because no item came back, after a put and after an update in a transaction.
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
     * The item exists, since every update is conditioned on it.
     */
    case Update;

    /**
     * An item's key, to look it up, delete it or update it by. Only the key fields are present.
     * A pagination cursor keeps the key DynamoDB returned as it is, without the normalizer.
     */
    case Key;

    /**
     * An item read from DynamoDB. Every field is present, and one the row has none of is `null`, or its default
     * if it is not nullable: `null` is how a nullable field is stored as absent.
     */
    case Read;
}
