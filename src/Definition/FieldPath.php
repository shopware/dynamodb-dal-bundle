<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Definition;

use Shopware\DynamodbDalBundle\Exception\AttributeTypeMismatchException;
use Shopware\DynamodbDalBundle\Exception\UnknownFieldException;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;

/**
 * A document path into an item — `name`, `settings.currency`, `entries[0].id`.
 *
 * DynamoDB addresses a map entry or list element by path, which is what lets a filter test one
 * entry and an update write one entry without reading or rewriting the attribute around it.
 * Both sides parse a path the same way, so the grammar lives here rather than in either of them.
 *
 * @internal
 */
final class FieldPath
{
    /**
     * Validates the whole path to avoid parsing e.g. `settings[color]`, `settings[0]x` or `settings.` typos,
     * potentially targeting accessible but unintended attributes.
     */
    private const string GRAMMAR = '/^[^.\[\]]+(?:\.[^.\[\]]+|\[\d+\])*$/';

    private const string PATTERN = '/(?P<attribute>[^.\[\]]+)|\[(?P<index>\d+)\]/';

    /**
     * @param non-empty-list<string|int> $segments - an attribute name, or a list index
     */
    private function __construct(
        public readonly FieldDefinition $definition,
        public readonly string $path,
        public readonly array $segments,
    ) {
    }

    /**
     * Throws for a path that does not address anything on this definition: invalid syntax, an unknown field,
     * nesting deeper than the field's type, or an index into a map or a key into a list.
     *
     * @throws UnknownFieldException
     */
    public static function parse(EntityDefinition $definition, string $path): self
    {
        if (preg_match(self::GRAMMAR, $path) !== 1) {
            throw new UnknownFieldException($definition, $path);
        }

        preg_match_all(self::PATTERN, $path, $matches, \PREG_SET_ORDER);

        $segments = [];
        foreach ($matches as $match) {
            $index = $match['index'] ?? '';
            $segments[] = $index !== '' ? (int) $index : ($match['attribute'] ?? '');
        }

        // The grammar already opens on an attribute, which has to be a string
        if (!\is_string($segments[0] ?? null) || $segments[0] === '') {
            throw new UnknownFieldException($definition, $path);
        }

        $field = $definition->getFieldDefinition($segments[0]);

        for ($i = 1, $count = \count($segments); $field !== null && $i < $count; ++$i) {
            // Lists and maps are both `array`, so only the stored type tells whether an index or a key fits
            $container = \is_int($segments[$i]) ? AttributeType::List : AttributeType::Map;
            if ($field->getAttributeType() !== $container) {
                throw new UnknownFieldException($definition, $path);
            }

            $field = $field->getValueFieldDefinition();
        }

        // An unknown root, or a segment deeper than the field's type nests
        if ($field === null) {
            throw new UnknownFieldException($definition, $path);
        }

        return new self($field, $path, $segments);
    }

    /**
     * Where in the item a failure on `$field` happened, from the path the failing code named, if any.
     */
    public static function locate(FieldDefinition $field, ?string $path): string
    {
        $name = $field->getName();

        return match (true) {
            $path === null || $path === '' => $name,
            // A collection serializer names the element it was on, opening on its separator
            str_starts_with($path, '[') || str_starts_with($path, '.') => $name . $path,
            // A whole path already opens on the property; a value definition is named `property.value`
            str_starts_with($path, strstr($name, '.', true) ?: $name) => $path,
            // Anything else names a place below the field
            default => $name . '.' . $path,
        };
    }

    public function isNested(): bool
    {
        return \count($this->segments) > 1;
    }

    /**
     * The value at this path in a serialized item, as its field serializers wrote it, or `null` where the item holds
     * nothing there: a key its map lacks, or `NULL`, as which a map or a list stores an entry that is `null`. The value
     * is serialized still, for the caller to deserialize with {@see self::$definition} if it needs to.
     *
     * @param array<string, AttributeValue> $item - keyed by attribute name, as DynamoDB takes and returns an item
     *
     * @throws AttributeTypeMismatchException if a segment descends into an attribute that is not a map or a list
     */
    public function traverse(array $item): ?AttributeValue
    {
        $segments = $this->segments;
        $walked = (string) array_shift($segments);
        $value = $item[$walked] ?? null;

        foreach ($segments as $segment) {
            $type = $value !== null ? AttributeType::tryFromAttributeValue($value) : null;
            if ($type === null) {
                return null;
            }

            $container = \is_int($segment) ? AttributeType::List : AttributeType::Map;
            if ($type !== $container) {
                throw new AttributeTypeMismatchException($this->definition->getEntityDefinition(), $walked, $type, [$container]);
            }

            $value = \is_int($segment) ? ($value->getL()[$segment] ?? null) : ($value->getM()[$segment] ?? null);
            $walked .= \is_int($segment) ? "[{$segment}]" : ".{$segment}";
        }

        return $value?->getNull() === true ? null : $value;
    }

    public function getExpression(): string
    {
        $expression = '';

        foreach ($this->segments as $segment) {
            if (\is_int($segment)) {
                $expression .= "[{$segment}]";

                continue;
            }

            $expression .= $expression === '' ? '' : '.';
            $expression .= '#' . self::slug($segment);
        }

        return $expression;
    }

    /**
     * @return array<string, string> - `['#settings' => 'settings', '#currency' => 'currency']`
     */
    public function getExpressionAttributeNames(): array
    {
        $names = [];

        foreach ($this->segments as $segment) {
            if (\is_int($segment)) {
                continue;
            }

            $placeholder = '#' . self::slug($segment);
            $names[$placeholder] = $segment;
        }

        return $names;
    }

    public function getAttributeValueName(string $prefix): string
    {
        return ":{$prefix}_" . self::slug($this->path);
    }

    /**
     * Reduces a name to the characters a placeholder may carry (`[A-Za-z0-9_]`), reversibly.
     *
     * Reversible is the point: a lossy reduction would map `sub-1` and `sub.1` onto one placeholder,
     * and the caller would have to break the tie with a counter. Every byte outside the set becomes
     * `_` plus its hex code, and a literal `_` doubles, so no two names can ever reduce alike and the
     * placeholder can be derived from the name instead of allocated.
     *
     * @param string $name - an attribute name, or a whole path when a value placeholder needs a stem
     */
    private static function slug(string $name): string
    {
        return (string) preg_replace_callback(
            '/[^A-Za-z0-9]/',
            static fn (array $match): string => $match[0] === '_' ? '__' : \sprintf('_%02x', \ord($match[0])),
            $name,
        );
    }
}
