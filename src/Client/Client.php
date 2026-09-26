<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Client;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Client\Input\BatchWriteInput;
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
use Shopware\DynamodbDalBundle\Exception\ConditionEmptyException;
use Shopware\DynamodbDalBundle\Exception\DALException;
use Shopware\DynamodbDalBundle\Exception\FieldMissingSerializedValueException;
use Shopware\DynamodbDalBundle\Exception\InvalidCursorException;
use Shopware\DynamodbDalBundle\Exception\UnknownEntityDefinitionException;
use Shopware\DynamodbDalBundle\Exception\UpdateDuplicatePathException;
use Shopware\DynamodbDalBundle\Exception\UpdateEmptyException;
use AsyncAws\Core\Exception\Exception as AsyncAwsException;
use AsyncAws\DynamoDb\Exception\ConditionalCheckFailedException;
use AsyncAws\DynamoDb\Exception\TransactionCanceledException;

/**
 * The entry point for reading and writing entities.
 * Callers pass and receive entities and plain PHP values only;
 * how each field is stored follows from the entity class an input names.
 *
 * @final - considered final, but not marked as such so a test can double it
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
     * Reads the item stored under `$key` with `GetItem`, or `null` if there is none.
     *
     * @template Entity of AbstractEntity
     *
     * @param Key<Entity> $key
     *
     * @throws UnknownEntityDefinitionException
     * @throws DALException if the key does not serialize, or the stored item does not deserialize
     * @throws AsyncAwsException if a request to DynamoDB fails
     *
     * @return ?Entity
     */
    public function find(Key $key, bool $consistentRead = false): ?AbstractEntity
    {
        foreach ($this->reader->get(new GetInput([$key], $consistentRead)) as $entity) {
            return $entity;
        }

        return null;
    }

    /**
     * Opens a {@see GetOutput} over the items stored under `$keys`, which may span entity classes.
     * Shorthand for {@see get()} with a {@see GetInput} of these keys.
     *
     * @template Entity of AbstractEntity
     *
     * @param list<Key<Entity>> $keys
     *
     * @throws UnknownEntityDefinitionException once the output is read
     * @throws DALException once the output is read, if a key does not serialize, or a stored item does not deserialize
     * @throws AsyncAwsException once the output is read, if a request to DynamoDB fails
     *
     * @return GetOutput<Entity>
     */
    public function findMany(array $keys, bool $consistentRead = false): GetOutput
    {
        return $this->get(new GetInput($keys, $consistentRead));
    }

    /**
     * Opens a {@see GetOutput} over items read by key, spanning one or multiple tables. Nothing is read until the
     * output is, as for a search. One key is a `GetItem`, several are `BatchGetItem`s chunked at 100 keys, and key
     * order is not preserved.
     *
     * @template Entity of AbstractEntity
     *
     * @param GetInput<Entity> $input
     *
     * @throws UnknownEntityDefinitionException once the output is read
     * @throws DALException once the output is read, if a key does not serialize, or a stored item does not deserialize
     * @throws AsyncAwsException once the output is read, if a request to DynamoDB fails
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
     *
     * @throws UnknownEntityDefinitionException
     * @throws DALException if a key does not serialize, or a stored item does not deserialize
     * @throws AsyncAwsException if a request to DynamoDB fails
     */
    public function refresh(RefreshInput $input): void
    {
        $this->reader->refresh($input);
    }

    /**
     * Opens a {@see SearchOutput} over a {@see ScanInput}/{@see QueryInput}'s matches.
     * Nothing is read until the output is, and that is also when a wrong class or cursor fails.
     * For a server-side total use {@see count()}.
     *
     * @template Entity of AbstractEntity
     *
     * @param ScanInput<Entity>|QueryInput<Entity> $query
     *
     * @throws UnknownEntityDefinitionException once the output is read
     * @throws InvalidCursorException once the output is read
     * @throws DALException once the output is read, if the query does not compile, or an item does not deserialize
     * @throws AsyncAwsException once the output is read, if a request to DynamoDB fails
     *
     * @return SearchOutput<Entity>
     */
    public function search(ScanInput|QueryInput $query): SearchOutput
    {
        return new SearchOutput($this->reader->search($query), $query);
    }

    /**
     * Counts matches via `Select=COUNT`, summed across all pages. The input's `limit` and `cursor` do not apply.
     *
     * @param ScanInput<AbstractEntity>|QueryInput<AbstractEntity> $query
     *
     * @throws UnknownEntityDefinitionException
     * @throws DALException if the query does not compile
     * @throws AsyncAwsException if a request to DynamoDB fails
     */
    public function count(ScanInput|QueryInput $query): int
    {
        return $this->reader->count($query);
    }

    /**
     * Writes the whole item with `PutItem`, replacing a stored one with the same key.
     * Values the normalizer generates are applied back onto the entity.
     *
     * @template Entity of AbstractEntity
     *
     * @param PutInput<Entity> $input
     *
     * @throws UnknownEntityDefinitionException
     * @throws ConditionEmptyException
     * @throws DALException if the entity or the condition does not serialize
     * @throws ConditionalCheckFailedException when the condition fails
     * @throws AsyncAwsException if a request to DynamoDB fails otherwise
     */
    public function put(PutInput $input): void
    {
        $this->writer->put($input);
    }

    /**
     * Updates an existing item with `UpdateItem`.
     * An update never creates an item: a key without one fails like a failed condition.
     *
     * @template Entity of AbstractEntity
     *
     * @param UpdateInput<Entity> $input
     *
     * @throws UnknownEntityDefinitionException
     * @throws ConditionEmptyException
     * @throws UpdateEmptyException if the update has nothing to write
     * @throws UpdateDuplicatePathException if the update gives a path two values
     * @throws FieldMissingSerializedValueException if the update removes a field that is not nullable
     * @throws DALException if the update, the key or the condition does not serialize, or the stored item does not deserialize
     * @throws ConditionalCheckFailedException when the item does not exist or the condition fails
     * @throws AsyncAwsException if a request to DynamoDB fails otherwise
     */
    public function update(UpdateInput $input): void
    {
        $this->writer->update($input);
    }

    /**
     * Deletes the item by {@see Key} or entity with `DeleteItem`.
     * Deleting an item that does not exist is not an error.
     *
     * @template Entity of AbstractEntity
     *
     * @param DeleteInput<Entity> $input
     *
     * @throws UnknownEntityDefinitionException
     * @throws ConditionEmptyException
     * @throws DALException if the key or the condition does not serialize
     * @throws ConditionalCheckFailedException when the condition fails
     * @throws AsyncAwsException if a request to DynamoDB fails otherwise
     */
    public function delete(DeleteInput $input): void
    {
        $this->writer->delete($input);
    }

    /**
     * Writes puts and deletes spanning one or multiple tables with `BatchWriteItem`, 25 per request, resubmitting whatever DynamoDB leaves unprocessed.
     * Not atomic, and without conditions; for either, use {@see transactWrite()}.
     * Once every request succeeded, values the normalizer generated are applied back onto each put entity.
     *
     * @throws UnknownEntityDefinitionException
     * @throws DALException if an entity or a key does not serialize
     * @throws AsyncAwsException if a request to DynamoDB fails
     */
    public function batchWrite(BatchWriteInput $input): void
    {
        $this->writer->batchWrite($input);
    }

    /**
     * Writes puts, updates and deletes spanning one or multiple tables with `TransactWriteItems`, in the order they are given.
     * Past 100 operations, the input is split into several transactions, each atomic on its own.
     * Once they succeeded, values the normalizer generated are applied back onto each put entity, and an update keyed
     * by an entity brings it up to date as its {@see UpdateInput::$refresh} says.
     *
     * @throws UnknownEntityDefinitionException
     * @throws ConditionEmptyException
     * @throws UpdateEmptyException if an update has nothing to write
     * @throws UpdateDuplicatePathException if an update gives a path two values
     * @throws FieldMissingSerializedValueException if an update removes a field that is not nullable
     * @throws DALException if an entity, an update, a key or a condition does not serialize, or a stored item does not deserialize
     * @throws TransactionCanceledException e.g. when a condition fails or an updated item does not exist; a conflict is retried first
     * @throws AsyncAwsException if a request to DynamoDB fails otherwise
     */
    public function transactWrite(TransactWriteInput $input): void
    {
        $this->writer->transactWrite($input);
    }
}
