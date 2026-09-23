<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Client;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Client\Input\DeleteInput;
use Shopware\DynamodbDalBundle\Client\Input\GetInput;
use Shopware\DynamodbDalBundle\Client\Input\PutInput;
use Shopware\DynamodbDalBundle\Client\Input\QueryInput;
use Shopware\DynamodbDalBundle\Client\Input\ScanInput;
use Shopware\DynamodbDalBundle\Client\Input\TransactWriteInput;
use Shopware\DynamodbDalBundle\Client\Input\UpdateInput;
use Shopware\DynamodbDalBundle\Client\Output\GetOutput;
use Shopware\DynamodbDalBundle\Client\Output\SearchOutput;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;

/**
 * Table-agnostic facade over the {@see ReaderClient}: each method takes the {@see EntityDefinition} it
 * operates on, wraps reads in result objects, and owns the write path. Callers work with entities; the
 * `AttributeValue` (de)serialization lives in the reader and serializer.
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
     * Opens a {@see SearchOutput} over a {@see ScanInput}/{@see QueryInput}'s matches. For a cheap
     * server-side total use {@see count()}.
     *
     * @template Entity of AbstractEntity
     *
     * @param EntityDefinition<Entity> $definition
     *
     * @return SearchOutput<Entity>
     */
    public function search(EntityDefinition $definition, ScanInput|QueryInput $query): SearchOutput
    {
        return new SearchOutput(
            $this->reader->search($definition, $query),
            $definition,
            $query,
        );
    }

    /**
     * Counts matches via `Select=COUNT`, summed across all pages.
     *
     * @param EntityDefinition<AbstractEntity> $definition
     */
    public function count(EntityDefinition $definition, ScanInput|QueryInput $query): int
    {
        return $this->reader->count($definition, $query);
    }

    /**
     * @template Entity of AbstractEntity
     *
     * @param EntityDefinition<Entity> $definition
     * @param PutInput<Entity> ...$inputs
     */
    public function put(EntityDefinition $definition, PutInput ...$inputs): void
    {
        $this->writer->put($definition, ...$inputs);
    }

    /**
     * @template Entity of AbstractEntity
     *
     * @param EntityDefinition<Entity> $definition
     * @param UpdateInput<Entity> ...$inputs
     */
    public function update(EntityDefinition $definition, UpdateInput ...$inputs): void
    {
        $this->writer->update($definition, ...$inputs);
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
     * @param EntityDefinition<Entity> $definition
     * @param DeleteInput<Entity> ...$inputs
     */
    public function delete(EntityDefinition $definition, DeleteInput ...$inputs): void
    {
        $this->writer->delete($definition, ...$inputs);
    }
}
