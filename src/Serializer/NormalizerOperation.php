<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Serializer;

/**
 * Why a normalizer runs, as {@see NormalizerContext::$operation} tells it. It names what the fields are, so
 * `denormalize()` sees `Put` or `Update` when the fields a write stored are applied back onto its entity.
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
     * An item's key: to look it up, delete it or update it, or read from a pagination cursor. Only the key
     * fields are present.
     */
    case Key;

    /**
     * An item read from DynamoDB. Every field is present, as the field's default or `null` where the row has none.
     */
    case Read;
}
