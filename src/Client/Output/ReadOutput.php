<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Client\Output;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Exception\DALException;
use AsyncAws\Core\Exception\Exception as AsyncAwsException;

/**
 * A DynamoDB read result that streams its source generator **once**, whether a search or a key read produced it.
 * It is single-use: the first read of it consumes the stream, and any further read throws.
 * There is no buffering, so a large scan or key read never accumulates its rows in memory.
 *
 * @template Entity of AbstractEntity
 * @template Key - what the source keys each entity by; consumers only ever see positions
 *
 * @implements \IteratorAggregate<int, Entity>
 */
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
     * Streams the result, keyed by position.
     *
     * @throws \LogicException if this output has already been read
     * @throws DALException if the read fails in the DAL, e.g. on an item that does not deserialize
     * @throws AsyncAwsException if a request to DynamoDB fails
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
     * @throws \LogicException if this output has already been read
     * @throws DALException if the read fails in the DAL, e.g. on an item that does not deserialize
     * @throws AsyncAwsException if a request to DynamoDB fails
     *
     * @return list<Entity>
     */
    public function toArray(): array
    {
        return iterator_to_array($this->getIterator(), false);
    }

    /**
     * @throws \LogicException if this output has already been read
     * @throws DALException if the read fails in the DAL, e.g. on an item that does not deserialize
     * @throws AsyncAwsException if a request to DynamoDB fails
     *
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
     * @throws \LogicException if this output has already been read
     * @throws DALException if the read fails in the DAL, e.g. on an item that does not deserialize
     * @throws AsyncAwsException if a request to DynamoDB fails
     *
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
