<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Client\Cursor;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Several named {@see Cursor} positions resumed as one logical page — an overview that merges one
 * query per status keeps a cursor per status, keyed by status value.
 *
 * @implements \IteratorAggregate<string, Cursor>
 * @implements \ArrayAccess<string, Cursor>
 */
#[Exclude]
final class CursorCollection implements \IteratorAggregate, \ArrayAccess, \JsonSerializable
{
    /**
     * @param array<string, Cursor> $cursors - name => position
     */
    public function __construct(
        public readonly array $cursors = [],
    ) {
    }

    public function get(string $name): ?Cursor
    {
        return $this->cursors[$name] ?? null;
    }

    public function with(string $name, Cursor $cursor): self
    {
        return new self([...$this->cursors, $name => $cursor]);
    }

    public function isEmpty(): bool
    {
        return $this->cursors === [];
    }

    /**
     * @return \Traversable<string, Cursor>
     */
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->cursors);
    }

    public function offsetExists(mixed $offset): bool
    {
        return \is_string($offset) && isset($this->cursors[$offset]);
    }

    public function offsetGet(mixed $offset): ?Cursor
    {
        return \is_string($offset) ? ($this->cursors[$offset] ?? null) : null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException(self::class . ' is immutable; derive a new instance with with().');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException(self::class . ' is immutable; derive a new instance with with().');
    }

    /**
     * @return array<string, Cursor>
     */
    public function jsonSerialize(): array
    {
        return $this->cursors;
    }
}
