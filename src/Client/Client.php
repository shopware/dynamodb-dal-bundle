<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Client;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Client\Input\BatchWriteInput;
use Shopware\DynamodbDalBundle\Client\Input\DeleteInput;
use Shopware\DynamodbDalBundle\Client\Input\GetInput;
use Shopware\DynamodbDalBundle\Client\Input\InsertInput;
use Shopware\DynamodbDalBundle\Client\Input\PutInput;
use Shopware\DynamodbDalBundle\Client\Input\QueryInput;
use Shopware\DynamodbDalBundle\Client\Input\RefreshInput;
use Shopware\DynamodbDalBundle\Client\Input\ScanInput;
use Shopware\DynamodbDalBundle\Client\Input\TransactWriteInput;
use Shopware\DynamodbDalBundle\Client\Input\UpdateInput;
use Shopware\DynamodbDalBundle\Client\Input\UpsertInput;
use Shopware\DynamodbDalBundle\Client\Output\GetOutput;
use Shopware\DynamodbDalBundle\Client\Output\SearchOutput;
use Shopware\DynamodbDalBundle\Client\Output\UpsertOutcome;
use Shopware\DynamodbDalBundle\Client\Read\ReaderClient;
use Shopware\DynamodbDalBundle\Client\Write\WriterClient;
use Shopware\DynamodbDalBundle\Exception\ConditionEmptyException;
use Shopware\DynamodbDalBundle\Exception\DALException;
use Shopware\DynamodbDalBundle\Exception\DuplicateKeyException;
use Shopware\DynamodbDalBundle\Exception\EntityOutOfSyncException;
use Shopware\DynamodbDalBundle\Exception\FieldMissingSerializedValueException;
use Shopware\DynamodbDalBundle\Exception\InvalidCursorException;
use Shopware\DynamodbDalBundle\Exception\InvalidKeyConditionException;
use Shopware\DynamodbDalBundle\Exception\UnknownEntityDefinitionException;
use Shopware\DynamodbDalBundle\Exception\UnknownIndexException;
use Shopware\DynamodbDalBundle\Exception\UpdateDuplicatePathException;
use Shopware\DynamodbDalBundle\Exception\UpdateEmptyException;
use Shopware\DynamodbDalBundle\Exception\UpsertContentionException;
use Shopware\DynamodbDalBundle\Exception\UpsertKeyMismatchException;
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
     * @throws UnknownIndexException once the output is read
     * @throws InvalidKeyConditionException once the output is read
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
     * @throws UnknownIndexException
     * @throws InvalidKeyConditionException
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
     * @throws EntityOutOfSyncException if the put is stored, but the normalizer fails on what it wrote
     * @throws AsyncAwsException if a request to DynamoDB fails otherwise
     */
    public function put(PutInput $input): void
    {
        $this->writer->put($input);
    }

    /**
     * Writes the whole item with `PutItem`, where no item is stored with the same key. A stored one is left as it is, never replaced.
     * Values the normalizer generates are applied back onto the entity.
     *
     * @template Entity of AbstractEntity
     *
     * @param InsertInput<Entity>|Entity $input - the insert, or the entity to insert
     *
     * @throws UnknownEntityDefinitionException
     * @throws DALException if the entity does not serialize
     * @throws EntityOutOfSyncException if the insert is stored, but the normalizer fails on what it wrote
     * @throws AsyncAwsException if a request to DynamoDB fails otherwise
     *
     * @return bool - `false` where an item is stored under the entity's key, and nothing is written
     */
    public function insert(InsertInput|AbstractEntity $input): bool
    {
        return $this->writer->insert($input instanceof InsertInput ? $input : new InsertInput($input));
    }

    /**
     * Updates an existing item with `UpdateItem`.
     * An update never creates an item: where none is stored under the key, it writes nothing.
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
     * @throws DALException if the update, the key or the condition does not serialize
     * @throws ConditionalCheckFailedException when the condition fails on the stored item
     * @throws EntityOutOfSyncException if the update is stored, but the stored item does not deserialize
     * @throws AsyncAwsException if a request to DynamoDB fails otherwise
     *
     * @return bool - `false` where no item is stored under the key, and nothing is written
     */
    public function update(UpdateInput $input): bool
    {
        return $this->writer->update($input);
    }

    /**
     * Writes the entity whether or not its item is stored: it updates the stored item with `UpdateItem`, and where the
     * update finds none, puts the entity with `PutItem`. A stored item takes what {@see UpsertInput::$update} says,
     * and the entity is brought up to date as after that update or that put.
     * Not atomic across the two requests, but only one of them is written, and the outcome says which.
     *
     * @template Entity of AbstractEntity
     *
     * @param UpsertInput<Entity> $input
     *
     * @throws UnknownEntityDefinitionException
     * @throws ConditionEmptyException
     * @throws UpdateEmptyException if the update has nothing to write
     * @throws UpdateDuplicatePathException if the update gives a path two values
     * @throws FieldMissingSerializedValueException if the update removes a field that is not nullable, or the entity lacks a required value
     * @throws UpsertKeyMismatchException if the normalizer gives the key other values for the put than for the update
     * @throws DALException if the entity, the update, a path, the key or the condition does not serialize
     * @throws ConditionalCheckFailedException when the condition fails
     * @throws UpsertContentionException when, in both rounds, the update finds no item and the put then finds one that another writer created
     * @throws EntityOutOfSyncException if the upsert is stored, but the entity could not be brought up to date; its `upsertOutcome` says which write is stored
     * @throws AsyncAwsException if a request to DynamoDB fails otherwise
     */
    public function upsert(UpsertInput $input): UpsertOutcome
    {
        return $this->writer->upsert($input);
    }

    /**
     * Deletes the item by {@see Key} or entity with `DeleteItem`.
     * Deleting an item that does not exist is not an error, whatever the condition.
     *
     * @template Entity of AbstractEntity
     *
     * @param DeleteInput<Entity> $input
     *
     * @throws UnknownEntityDefinitionException
     * @throws ConditionEmptyException
     * @throws DALException if the key or the condition does not serialize
     * @throws ConditionalCheckFailedException when the condition fails on the stored item
     * @throws AsyncAwsException if a request to DynamoDB fails otherwise
     *
     * @return bool - `false` where no item is stored under the key, and nothing is deleted
     */
    public function delete(DeleteInput $input): bool
    {
        return $this->writer->delete($input);
    }

    /**
     * Writes puts and deletes spanning one or multiple tables with `BatchWriteItem`, 25 per request, resubmitting whatever DynamoDB leaves unprocessed.
     * Not atomic, and without conditions; for either, use {@see transactWrite()}.
     * Values the normalizer generated are applied back onto each put entity DynamoDB stored, once every request is
     * sent, or once one fails, before its failure is thrown. The other entities keep their values.
     *
     * @throws UnknownEntityDefinitionException
     * @throws DuplicateKeyException if two of its puts and deletes name the same key
     * @throws DALException if an entity or a key does not serialize
     * @throws EntityOutOfSyncException if the batch is stored, but a normalizer fails on what a put wrote
     * @throws AsyncAwsException if a request to DynamoDB fails
     */
    public function batchWrite(BatchWriteInput $input): void
    {
        $this->writer->batchWrite($input);
    }

    /**
     * Writes puts, inserts, updates and deletes spanning one or multiple tables with `TransactWriteItems`, in the order they are given.
     * Past 100 operations, the input is split into several transactions, each atomic on its own.
     * Once every transaction is sent, or once one fails, before its failure is thrown, the entities of those stored are
     * brought up to date: values the normalizer generated are applied back onto each put or inserted entity, and an update keyed by
     * an entity brings it up to date as its {@see UpdateInput::$refresh} says.
     *
     * @throws UnknownEntityDefinitionException
     * @throws DuplicateKeyException if two of its operations name the same key
     * @throws ConditionEmptyException
     * @throws UpdateEmptyException if an update has nothing to write
     * @throws UpdateDuplicatePathException if an update gives a path two values
     * @throws FieldMissingSerializedValueException if an update removes a field that is not nullable
     * @throws DALException if an entity, an update, a key or a condition does not serialize
     * @throws TransactionCanceledException e.g. when a condition fails, an updated item does not exist or an inserted one is stored; a conflict or throttling is retried first
     * @throws EntityOutOfSyncException if the transactions are stored, but an entity could not be brought up to date
     * @throws AsyncAwsException if a request to DynamoDB fails otherwise
     */
    public function transactWrite(TransactWriteInput $input): void
    {
        $this->writer->transactWrite($input);
    }
}
