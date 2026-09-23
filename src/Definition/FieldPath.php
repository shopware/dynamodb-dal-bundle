<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Definition;

/**
 * A document path into an item — `name`, `settings.currency`, `entries[0].id`.
 *
 * DynamoDB addresses a map entry or list element by path, which is what lets a filter test one entry
 * and an update write one entry without reading or rewriting the attribute around it. Both sides parse
 * a path the same way, so the grammar lives here rather than in either of them.
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
     * Null for a path that does not address anything on this definition: one the grammar rejects, one
     * whose first segment is not a field, or one that descends further than the field's type nests.
     */
    public static function tryParse(EntityDefinition $definition, string $path): ?self
    {
        if (preg_match(self::GRAMMAR, $path) !== 1) {
            return null;
        }

        preg_match_all(self::PATTERN, $path, $matches, \PREG_SET_ORDER);

        $segments = [];
        foreach ($matches as $match) {
            $index = $match['index'] ?? '';
            $segments[] = $index !== '' ? (int) $index : ($match['attribute'] ?? '');
        }

        // The grammar already opens on an attribute, which has to be a string
        if (!\is_string($segments[0] ?? null) || $segments[0] === '') {
            return null;
        }

        $field = $definition->getFieldDefinition($segments[0]);

        if ($field === null) {
            return null;
        }

        for ($i = 1, $count = \count($segments); $field !== null && $i < $count; ++$i) {
            $field = $field->getValueFieldDefinition();
        }

        if ($field === null) {
            return null;
        }

        return new self($field, $path, $segments);
    }

    public function isNested(): bool
    {
        return \count($this->segments) > 1;
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
