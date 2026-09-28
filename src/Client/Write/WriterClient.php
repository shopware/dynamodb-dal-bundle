<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Client\Write;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Client\Backoff;
use Shopware\DynamodbDalBundle\Client\Client;
use Shopware\DynamodbDalBundle\Client\Input\BatchWriteInput;
use Shopware\DynamodbDalBundle\Client\Input\DeleteInput;
use Shopware\DynamodbDalBundle\Client\Input\PutInput;
use Shopware\DynamodbDalBundle\Client\Input\Refresh;
use Shopware\DynamodbDalBundle\Client\Input\RefreshInput;
use Shopware\DynamodbDalBundle\Client\Input\TransactWriteInput;
use Shopware\DynamodbDalBundle\Client\Input\UpdateInput;
use Shopware\DynamodbDalBundle\Client\Read\ReaderClient;
use Shopware\DynamodbDalBundle\Exception\ConditionEmptyException;
use Shopware\DynamodbDalBundle\Exception\DALException;
use Shopware\DynamodbDalBundle\Exception\DuplicateKeyException;
use Shopware\DynamodbDalBundle\Exception\EntityOutOfSyncException;
use Shopware\DynamodbDalBundle\Exception\FieldMissingSerializedValueException;
use Shopware\DynamodbDalBundle\Exception\UnknownEntityDefinitionException;
use Shopware\DynamodbDalBundle\Exception\UpdateDuplicatePathException;
use Shopware\DynamodbDalBundle\Exception\UpdateEmptyException;
use Shopware\DynamodbDalBundle\Serializer\Serializer;
use AsyncAws\Core\Exception\Exception as AsyncAwsException;
use AsyncAws\DynamoDb\DynamoDbClient;
use AsyncAws\DynamoDb\Enum\ReturnValue;
use AsyncAws\DynamoDb\Exception\ConditionalCheckFailedException;
use AsyncAws\DynamoDb\Exception\TransactionCanceledException;
use AsyncAws\DynamoDb\ValueObject\TransactWriteItem;

/**
 * The write side behind {@see Client}: single-item writes, batches and transactions. {@see WriteRequestFactory} builds
 * what is sent; this decides how it is sent, and when the written entities are brought up to date.
 *
 * An entity is brought up to date once its write is stored, so a failure in that step is an
 * {@see EntityOutOfSyncException}, which tells the caller not to write again.
 *
 * @internal
 */
class WriterClient
{
    private const int BATCH_WRITE_LIMIT = 25;

    private const int TRANSACT_WRITE_LIMIT = 100;

    /**
     * @internal
     */
    public function __construct(
        protected readonly DynamoDbClient $client,
        protected readonly Serializer $serializer,
        protected readonly WriteRequestFactory $requests,
        protected readonly ReaderClient $reader,
    ) {
    }

    /**
     * Writes the entity with `PutItem`, then applies the serialized result back onto it so normalization-generated
     * values (e.g. a `Uuid::v7()` key or `createdAt`) are reflected on the entity.
     *
     * @template Entity of AbstractEntity
     *
     * @param PutInput<Entity> $input
     *
     * @throws UnknownEntityDefinitionException
     * @throws ConditionEmptyException
     * @throws DALException if the entity or the condition does not serialize
     * @throws ConditionalCheckFailedException
     * @throws EntityOutOfSyncException if the put is stored, but the normalizer fails on what it wrote
     * @throws AsyncAwsException if a request to DynamoDB fails otherwise
     */
    public function put(PutInput $input): void
    {
        $write = $this->requests->put($input);

        $this->client->putItem($write->request)->resolve();

        $this->writeBack($write->writeBack);
    }

    /**
     * Updates the item with `UpdateItem`. Unlike {@see self::put()}, an update writes only the fields it names.
     * Every update is conditioned on its item existing, so a missing key fails instead of creating a partial item.
     * An entity given as key takes the stored item back, unless {@see UpdateInput::$refresh} is {@see Refresh::None}.
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
     * @throws ConditionalCheckFailedException when the item does not exist or the condition fails
     * @throws EntityOutOfSyncException if the update is stored, but the stored item does not deserialize
     * @throws AsyncAwsException if a request to DynamoDB fails otherwise
     */
    public function update(UpdateInput $input): void
    {
        $write = $this->requests->update($input);

        // Alone, an update can return the stored item, which says all it wrote, so it is asked for instead of the write-back
        $entity = $write->writeBack?->entity;

        $output = $this->client->updateItem([
            ...$write->request,
            ...($entity ? ['ReturnValues' => ReturnValue::ALL_NEW] : []),
        ]);

        $output->resolve();

        if ($entity) {
            try {
                $this->serializer->deserialize($write->key->definition, $output->getAttributes(), $entity);
            } catch (DALException $exception) {
                throw new EntityOutOfSyncException($exception);
            }
        }
    }

    /**
     * Deletes the item with `DeleteItem`. Deleting an item that does not exist is not an error.
     *
     * @template Entity of AbstractEntity
     *
     * @param DeleteInput<Entity> $input
     *
     * @throws UnknownEntityDefinitionException
     * @throws ConditionEmptyException
     * @throws DALException if the key or the condition does not serialize
     * @throws ConditionalCheckFailedException
     * @throws AsyncAwsException if a request to DynamoDB fails otherwise
     */
    public function delete(DeleteInput $input): void
    {
        $this->client->deleteItem($this->requests->delete($input)->request)->resolve();
    }

    /**
     * Writes puts and deletes across tables with `BatchWriteItem`, 25 per request, and resubmits whatever DynamoDB
     * leaves unprocessed after a growing pause. Not atomic: a failed request leaves the requests before it written.
     * Each put DynamoDB stored is applied back onto its entity, as for {@see self::put()}, once every request is sent,
     * or once one fails, before its failure is thrown. So after a failure, the entities that were written carry what
     * their normalizer generated, and the others don't.
     *
     * @throws UnknownEntityDefinitionException
     * @throws DuplicateKeyException if two of its puts and deletes name the same key
     * @throws DALException if an entity or a key does not serialize
     * @throws EntityOutOfSyncException if the batch is stored, but a normalizer fails on what a put wrote
     * @throws AsyncAwsException if a request to DynamoDB fails
     */
    public function batchWrite(BatchWriteInput $input): void
    {
        $batch = $this->requests->batch($input);
        $stored = [];

        try {
            foreach ($batch->chunks(self::BATCH_WRITE_LIMIT) as $chunk) {
                $requestItems = $chunk->requestItems();

                for ($round = 1; $requestItems !== []; ++$round) {
                    // Keyed by table like the request, so the leftovers can be sent again as they are; empty is done
                    $requestItems = $this->client->batchWriteItem(['RequestItems' => $requestItems])->getUnprocessedItems();

                    // A put that is not left over is stored, whatever happens to the requests after it
                    array_push($stored, ...$chunk->settle($requestItems));

                    if ($requestItems !== []) {
                        Backoff::wait($round);
                    }
                }
            }
        } catch (\Throwable $exception) {
            $this->writeBackAfterFailure(...$stored);

            throw $exception;
        }

        $this->writeBack(...$stored);
    }

    /**
     * Writes puts, updates and deletes across tables with `TransactWriteItems`, in the order the operations were added.
     * Past 100 operations, the input is split into several transactions, each atomic on its own. A cancellation for a
     * conflict or for throttling is retried with backoff; any other cancellation is rethrown.
     *
     * Once every transaction is sent, or once one fails, before its failure is thrown, the serialized result of each put
     * stored is applied back onto its entity, as for {@see self::put()}, and each update stored that is keyed by an
     * entity brings it up to date as its {@see UpdateInput::$refresh} says. Only {@see Refresh::Full} reads an item
     * back, where an update writes a nested path or has an action.
     *
     * @throws UnknownEntityDefinitionException
     * @throws DuplicateKeyException if two of its operations name the same key
     * @throws ConditionEmptyException
     * @throws UpdateEmptyException if an update has nothing to write
     * @throws UpdateDuplicatePathException if an update gives a path two values
     * @throws FieldMissingSerializedValueException if an update removes a field that is not nullable
     * @throws DALException if an entity, an update, a key or a condition does not serialize
     * @throws TransactionCanceledException e.g. when a condition fails or an updated item does not exist; a conflict or throttling is retried first
     * @throws EntityOutOfSyncException if the transactions are stored, but an entity could not be brought up to date
     * @throws AsyncAwsException if a request to DynamoDB fails otherwise
     */
    public function transactWrite(TransactWriteInput $input): void
    {
        $writes = $this->requests->transaction($input);
        $stored = [];

        try {
            foreach (array_chunk($writes, self::TRANSACT_WRITE_LIMIT) as $chunk) {
                $items = array_map(static fn (PreparedWrite $write): TransactWriteItem => $write->toTransactItem(), $chunk);

                TransactionRetry::run(fn () => $this->client->transactWriteItems([
                    'TransactItems' => $items,
                    'ClientRequestToken' => bin2hex(random_bytes(16)),
                ])->resolve());

                // Each transaction is atomic on its own, so it is stored whatever happens to the ones after it
                array_push($stored, ...array_map(static fn (PreparedWrite $write): ?WriteBack => $write->writeBack, $chunk));
            }
        } catch (\Throwable $exception) {
            $this->writeBackAfterFailure(...$stored);

            throw $exception;
        }

        $this->writeBack(...$stored);
    }

    /**
     * Brings the entities of writes just stored up to date. Those read back are read in one go.
     *
     * @throws EntityOutOfSyncException if one could not be, although its write is stored
     */
    private function writeBack(?WriteBack ...$writeBacks): void
    {
        try {
            $readBacks = [];
            foreach (array_filter($writeBacks) as $writeBack) {
                if ($writeBack->fields === null) {
                    $readBacks[] = $writeBack->entity;

                    continue;
                }

                // A path into an attribute is no field the entity could take
                $fields = array_filter(
                    $writeBack->fields,
                    static fn (string $name): bool => $writeBack->definition->getFieldDefinition($name) !== null,
                    \ARRAY_FILTER_USE_KEY,
                );

                $writeBack->entity->setVars($this->serializer->denormalize($writeBack->definition, $fields, $writeBack->operation));
            }

            if ($readBacks !== []) {
                // Strongly consistent, so the read-back is guaranteed to observe the write just made
                $this->reader->refresh(new RefreshInput($readBacks, consistentRead: true));
            }
        } catch (DALException|AsyncAwsException $exception) {
            throw new EntityOutOfSyncException($exception);
        }
    }

    /**
     * Brings the entities of the writes stored before a batch or transaction failed up to date, as far as they can
     * be. The caller has to handle the write's own failure, so a failure here is dropped rather than put in its place.
     */
    private function writeBackAfterFailure(?WriteBack ...$writeBacks): void
    {
        try {
            $this->writeBack(...$writeBacks);
        } catch (EntityOutOfSyncException) {
            // The failure of the write is thrown instead
        }
    }
}
