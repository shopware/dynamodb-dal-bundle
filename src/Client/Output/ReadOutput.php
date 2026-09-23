<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Client\Output;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * A DynamoDB read result that streams its source generator **once**. It is single-use: the first of
 * {@see getIterator()}, {@see toArray()} or {@see first()} consumes the stream, and any further call to
 * any of them throws — there is no buffering, so a large scan never accumulates its rows in memory.
 *
 * @template Entity of AbstractEntity
 * @template Key - what the source keys each entity by; consumers only ever see positions
 *
 * @implements \IteratorAggregate<int, Entity>
 */
#[Exclude]
abstract class ReadOutput implements \IteratorAggregate
{
    private bool $consumed = false;

    /**
     * @internal
     *
     * @param \Generator<Key, Entity> $source
     */
    public function __construct(
        private readonly \Generator $source,
    ) {
    }

    /**
     * Streams the result exactly once, keyed by position. Throws if this output has already been consumed
     * by an earlier `getIterator()`/`toArray()`/`first()`/`page()` — build a new result from the same query
     * to read it again.
     *
     * @return \Generator<int, Entity>
     */
    public function getIterator(): \Generator
    {
        foreach ($this->stream() as $entity) {
            yield $entity;
        }
    }

    /**
     * @return list<Entity>
     */
    public function toArray(): array
    {
        return iterator_to_array($this->getIterator(), false);
    }

    /**
     * @return ?Entity
     */
    public function first(): ?AbstractEntity
    {
        foreach ($this->getIterator() as $entity) {
            return $entity;
        }

        return null;
    }

    /**
     * @return \Generator<Key, Entity>
     */
    protected function stream(): \Generator
    {
        if ($this->consumed) {
            throw new \LogicException('This read result has already been consumed; a ReadOutput streams its source once and cannot be re-read. Run the query again for a fresh result.');
        }

        $this->consumed = true;

        yield from $this->source;
    }
}
