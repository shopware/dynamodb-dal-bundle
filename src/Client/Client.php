<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Client;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Client\Input\DeleteInput;
use Shopware\DynamodbDalBundle\Client\Input\GetInput;
use Shopware\DynamodbDalBundle\Client\Input\PutInput;
use Shopware\DynamodbDalBundle\Client\Input\QueryInput;
use Shopware\DynamodbDalBundle\Client\Input\RefreshInput;
use Shopware\DynamodbDalBundle\Client\Input\ScanInput;
use Shopware\DynamodbDalBundle\Client\Input\TransactWriteInput;
use Shopware\DynamodbDalBundle\Client\Input\UpdateInput;
use Shopware\DynamodbDalBundle\Client\Output\GetOutput;
use Shopware\DynamodbDalBundle\Client\Output\SearchOutput;
use Shopware\DynamodbDalBundle\Exception\InvalidCursorException;
use Shopware\DynamodbDalBundle\Exception\UnknownEntityDefinitionException;

/**
 * The entry point for reading and writing entities.
 *
 * Every operation names the entity class it works on: as the first argument when a call covers one class, or
 * per key or operation in its input when it spans several ({@see get()}, {@see transactWrite()}).
 * {@see refresh()} takes the class from the entities it re-reads.
 * From that class the bundle knows the table, the keys and how each field is stored, so callers pass and receive entities and plain PHP values only.
 */
class Client
{
    /**
     * @internal
     */
    public function __construct(
        protected readonly ReaderClient $reader,
        protected readonly WriterClient $writer,
    ) {
    }

    /**
     * Reads items spanning one or multiple tables by key into a heterogeneous {@see GetOutput}.
     * One key is a `GetItem`, several are `BatchGetItem`s chunked at 100 keys, key order is not preserved.
     *
     * @template Entity of AbstractEntity
     *
     * @param GetInput<Entity> $input
     *
     * @return GetOutput<Entity>
     */
    public function get(GetInput $input): GetOutput
    {
        return new GetOutput($this->reader->get($input));
    }

    /**
     * Re-reads entities spanning one or multiple tables by their own key and writes the stored row back into
     * each instance. An entity whose row does not exist is left untouched.
     *
     * @template Entity of AbstractEntity
     *
     * @param RefreshInput<Entity> $input
     */
    public function refresh(RefreshInput $input): void
    {
        $this->reader->refresh($input);
    }

    /**
     * Opens a {@see SearchOutput} over a {@see ScanInput}/{@see QueryInput}'s matches.
     * Nothing is read until the output is, and that is also when a wrong class or cursor fails.
     * For a cheap server-side total use {@see count()}.
     *
     * @template Entity of AbstractEntity
     *
     * @param class-string<Entity> $class
     *
     * @throws UnknownEntityDefinitionException once the output is read, if no definition is registered for `$class`
     * @throws InvalidCursorException once the output is read, if the query's cursor is not a token of this query
     *
     * @return SearchOutput<Entity>
     */
    public function search(string $class, ScanInput|QueryInput $query): SearchOutput
    {
        return new SearchOutput(
            $this->reader->search($class, $query),
            $query,
        );
    }

    /**
     * Counts matches via `Select=COUNT`, summed across all pages. The input's `limit` does not apply.
     *
     * @param class-string<AbstractEntity> $class
     *
     * @throws UnknownEntityDefinitionException if no definition is registered for `$class`
     */
    public function count(string $class, ScanInput|QueryInput $query): int
    {
        return $this->reader->count($class, $query);
    }

    /**
     * @template Entity of AbstractEntity
     *
     * @param class-string<Entity> $class
     * @param PutInput<Entity> ...$inputs
     *
     * @throws UnknownEntityDefinitionException if no definition is registered for `$class`
     */
    public function put(string $class, PutInput ...$inputs): void
    {
        $this->writer->put($class, ...$inputs);
    }

    /**
     * @template Entity of AbstractEntity
     *
     * @param class-string<Entity> $class
     * @param UpdateInput<Entity> ...$inputs
     *
     * @throws UnknownEntityDefinitionException if no definition is registered for `$class`
     */
    public function update(string $class, UpdateInput ...$inputs): void
    {
        $this->writer->update($class, ...$inputs);
    }

    /**
     * @template Entity of AbstractEntity
     *
     * @param TransactWriteInput<Entity> $input
     */
    public function transactWrite(TransactWriteInput $input): void
    {
        $this->writer->transactWrite($input);
    }

    /**
     * Deletes items by {@see Index} or entity (idempotent).
     *
     * @template Entity of AbstractEntity
     *
     * @param class-string<Entity> $class
     * @param DeleteInput<Entity> ...$inputs
     *
     * @throws UnknownEntityDefinitionException if no definition is registered for `$class`
     */
    public function delete(string $class, DeleteInput ...$inputs): void
    {
        $this->writer->delete($class, ...$inputs);
    }
}
