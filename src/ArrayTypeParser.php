<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle;

/**
 * Parses PHPStan-style @var types for array properties to distinguish list<> (DynamoDB L) from array<> (DynamoDB M) and to extract the value type.
 * Only list<T> is treated as a list; all array<...> forms (including array<T> with no key type) are treated as maps.
 *
 * @internal
 */
final class ArrayTypeParser
{
    /**
     * Group 1 is the value type.
     */
    private const string LIST_REGEX = '/^list<(.+)>$/s';

    private const string MAP_REGEX = '/^array<.+>$/s';

    /**
     * Group 1 is the key type, group 2 the value type.
     */
    private const string ARRAY_KEY_VALUE_REGEX = '/^array<([^,]+),\s*(.+)>$/s';

    /**
     * Group 1 is the value type. It matches array<Key, Value> too, so try ARRAY_KEY_VALUE_REGEX first.
     */
    private const string ARRAY_SINGLE_REGEX = '/^array<(.+)>$/s';

    /**
     * Returns the @var type string from the property's docblock, or null if missing/unparseable.
     * E.g. "list<string>", "array<string, int>", "array<string>".
     */
    public static function getDocblockVarType(\ReflectionProperty $property): ?string
    {
        $doc = $property->getDocComment();
        if (!\is_string($doc) || $doc === '' || !preg_match('/@var\s+([^\n*]+)/', $doc, $m)) {
            return null;
        }

        return trim($m[1]) ?: null;
    }

    /**
     * Whether the type is a list, stored as a DynamoDB L.
     */
    public static function isListType(string $docblockType): bool
    {
        return (bool) preg_match(self::LIST_REGEX, $docblockType);
    }

    /**
     * Whether the type is a map, stored as a DynamoDB M.
     */
    public static function isMapType(string $docblockType): bool
    {
        return (bool) preg_match(self::MAP_REGEX, $docblockType);
    }

    public static function isListOrMapType(string $docblockType): bool
    {
        return self::isListType($docblockType) || self::isMapType($docblockType);
    }

    /**
     * Extracts the value type from list<T> or array<K,V> or array<T> (the T or V part).
     * For list<string> returns "string", for array<string, int> returns "int", for array<string> returns "string".
     */
    public static function extractValueType(string $docblockType): ?string
    {
        if (preg_match(self::LIST_REGEX, $docblockType, $m)) {
            return self::trimType($m[1]);
        }

        if (preg_match(self::ARRAY_KEY_VALUE_REGEX, $docblockType, $m)) {
            return self::trimType($m[2]);
        }

        if (preg_match(self::ARRAY_SINGLE_REGEX, $docblockType, $m)) {
            return self::trimType($m[1]);
        }

        return null;
    }

    private static function trimType(string $type): string
    {
        return trim($type, " \t\n\r");
    }
}
