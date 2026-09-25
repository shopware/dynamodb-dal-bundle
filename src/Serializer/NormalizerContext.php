<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Serializer;

/**
 * The fields a normalizer works on, keyed by field name or, for an update, by path, and why it runs.
 *
 * A normalizer changes them in place. `null` is absence, as everywhere in the DAL: a put leaves the
 * attribute out, an update removes it, and an update action whose value it is, such as `setIfNotExists()`,
 * writes nothing. A path that is not present is not written at all.
 */
final class NormalizerContext
{
    /**
     * @param array<string, mixed> $fields
     */
    private function __construct(
        public readonly NormalizerOperation $operation,
        private array $fields,
    ) {
    }

    /**
     * What the serializer builds for a normalizer, and what a normalizer's own test builds to run it.
     *
     * @param array<string, mixed> $fields
     */
    public static function fromFields(NormalizerOperation $operation, array $fields): self
    {
        return new self($operation, $fields);
    }

    /**
     * Whether the path is present, even as `null`: every field for a put or a read, the key fields for a key,
     * and what an update writes.
     */
    public function has(string $path): bool
    {
        return \array_key_exists($path, $this->fields);
    }

    /**
     * Whether the path or one nested under it is present, even as `null`, like {@see self::has()} but for a whole
     * attribute: `meta` for an update that writes `meta.kind`.
     * 
     * Usually only applies to maps and lists.
     */
    public function hasWithin(string $path): bool
    {
        return array_any(array_keys($this->fields), static fn (string $candidate): bool => self::isWithin($candidate, $path));
    }

    /**
     * The path's value, `null` for one that is absent or not present.
     */
    public function get(string $path): mixed
    {
        return $this->fields[$path] ?? null;
    }

    /**
     * The path and every path nested under it that is present, keyed by path: `['meta.kind' => 'invoice']` for
     * `meta`. A path only nests at a `.` or `[`, so `metadata` is not under `meta`.
     * 
     * Usually only applies to maps and lists.
     *
     * @return array<string, mixed>
     */
    public function getWithin(string $path): array
    {
        return array_filter(
            $this->fields,
            static fn (string $candidate): bool => self::isWithin($candidate, $path),
            \ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Sets the path, adding it where it is not present.
     */
    public function set(string $path, mixed $value): void
    {
        $this->fields[$path] = $value;
    }

    /**
     * Sets the path only where it is present and `null`, such as a generated ID on a put.
     * A path that is not present stays out, so a key or an update that does not name the field never gains it; {@see self::set()} adds one.
     */
    public function setIfUnset(string $path, mixed $value): void
    {
        if ($this->has($path) && $this->fields[$path] === null) {
            $this->fields[$path] = $value;
        }
    }

    /**
     * Makes the path absent: an update removes the attribute, and a put leaves it out.
     */
    public function remove(string $path): void
    {
        $this->set($path, null);
    }

    /**
     * Leaves the path out, so an update does not touch the attribute, and a read does not set the property. A read
     * still needs a value for every field that is neither nullable nor has a default, so omitting one fails like
     * leaving it `null`.
     */
    public function omit(string $path): void
    {
        unset($this->fields[$path]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getFields(): array
    {
        return $this->fields;
    }

    private static function isWithin(string $candidate, string $path): bool
    {
        return $candidate === $path
            || str_starts_with($candidate, $path . '.')
            || str_starts_with($candidate, $path . '[');
    }
}
