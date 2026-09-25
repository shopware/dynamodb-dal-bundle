<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Serializer;

/**
 * Used to normalize/denormalize multiple fields at once, e.g. for
 * - a composite primary key
 * - generated values, such as an ID or a timestamp, and one stamped on every update
 * - add missing but required fields
 * - migrate fields from one value to another of the same type
 *
 * Both sides change the fields through the {@see NormalizerContext}, whose {@see NormalizerContext::$operation}
 * says why they run. Override only the side you need, the other one leaves the fields as they are.
 */
abstract class AbstractNormalizer
{
    /**
     * Called before fields are serialized: a whole item for a put, the paths an update writes, or a key.
     * Never rely on a field being present; {@see NormalizerContext::has()} tells, and a field it names that is `null`
     * may be filled in here.
     */
    public function normalize(NormalizerContext $context): void
    {
    }

    /**
     * Called after fields are deserialized, possibly for only a subset of them: a whole item for a read, or the
     * fields a write sent, before they are applied back onto its entity.
     * Never rely on a field being present; a field {@see NormalizerContext::has()} names that is `null` may be filled in here.
     */
    public function denormalize(NormalizerContext $context): void
    {
    }
}
