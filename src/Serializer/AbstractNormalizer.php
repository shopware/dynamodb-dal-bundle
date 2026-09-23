<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Serializer;

/**
 * Used to normalize/denormalize multiple fields at once, e.g. for
 * - a composite primary key
 * - add missing but required fields
 * - migrate fields from one value to another of the same type
 *
 * @template FromFields of array<string, mixed> = array<string, mixed>
 * @template ToFields of array<string, mixed> = array<string, mixed>
 */
abstract class AbstractNormalizer
{
    /**
     * Called before an item is serialized, possibly for only a subset of its fields. Never rely on
     * a field being present in `$fields`; `$keys` lists what is being serialized, and any field it
     * names that is missing may be filled in here.
     *
     * @param FromFields $fields - `null` is passed if the property is not initialized.
     * @param array<string, string> $keys - `['fieldName' => 'fieldName']`
     *
     * @return ToFields
     */
    abstract public function normalize(array $fields, array $keys): array;

    /**
     * Called after an item is deserialized, possibly for only a subset of its fields. Never rely on
     * a field being present in `$fields`; `$keys` lists what was requested, and any field it names
     * that is missing may be filled in here.
     *
     * @param ToFields $fields - `null` is passed if the field did not exist in the DB.
     * @param array<string, string> $keys - `['fieldName' => 'fieldName']`
     *
     * @return FromFields
     */
    abstract public function denormalize(array $fields, array $keys): array;
}
