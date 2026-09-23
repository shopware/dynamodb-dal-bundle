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
     * Matches list<T>; capture group 1 = inner type (T).
     */
    private const string LIST_REGEX = '/^list<(.+)>$/s';

    /**
     * Matches any array<...> (map).
     */
    private const string MAP_REGEX = '/^array<.+>$/s';

    /**
     * Matches list<T> or array<...> (list or map) in one check.
     */
    private const string LIST_OR_MAP_REGEX = '/^(?:list<.+>|array<.+>)$/s';

    /**
     * Matches array<Key, Value>; group 1 = key type, group 2 = value type.
     */
    private const string ARRAY_KEY_VALUE_REGEX = '/^array<([^,]+),\s*(.+)>$/s';

    /**
     * Matches array<T> (single param); group 1 = inner type. Use only when ARRAY_KEY_VALUE_REGEX does not match.
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
     * Returns true if docblock type represents a list (sequential array → DynamoDB L).
     * Only explicit list<T> is a list; array<T> and array<Key, Value> are maps.
     */
    public static function isListType(string $docblockType): bool
    {
        return (bool) preg_match(self::LIST_REGEX, $docblockType);
    }

    /**
     * Returns true if docblock type represents a map (associative array → DynamoDB M).
     * Any array<...> is a map: array<Key, Value> or array<T> (single type = value type, key type undefined).
     */
    public static function isMapType(string $docblockType): bool
    {
        return (bool) preg_match(self::MAP_REGEX, $docblockType);
    }

    /**
     * Returns true if docblock type is list<T> or array<...> (map). Single check instead of isListType() || isMapType().
     */
    public static function isListOrMapType(string $docblockType): bool
    {
        return (bool) preg_match(self::LIST_OR_MAP_REGEX, $docblockType);
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
