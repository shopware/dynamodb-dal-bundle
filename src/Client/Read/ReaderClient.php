<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Client\Read;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Client\Client;
use Shopware\DynamodbDalBundle\Client\Input\GetInput;
use Shopware\DynamodbDalBundle\Client\Input\QueryInput;
use Shopware\DynamodbDalBundle\Client\Input\RefreshInput;
use Shopware\DynamodbDalBundle\Client\Input\ScanInput;
use Shopware\DynamodbDalBundle\Client\Backoff;
use Shopware\DynamodbDalBundle\Exception\DALException;
use Shopware\DynamodbDalBundle\Exception\InvalidCursorException;
use Shopware\DynamodbDalBundle\Exception\InvalidKeyConditionException;
use Shopware\DynamodbDalBundle\Exception\UnknownEntityDefinitionException;
use Shopware\DynamodbDalBundle\Exception\UnknownIndexException;
use Shopware\DynamodbDalBundle\Serializer\Serializer;
use AsyncAws\Core\Exception\Exception as AsyncAwsException;
use AsyncAws\DynamoDb\DynamoDbClient;
use AsyncAws\DynamoDb\Input\QueryInput as DynamoDbQueryInput;
use AsyncAws\DynamoDb\Input\ScanInput as DynamoDbScanInput;
use AsyncAws\DynamoDb\Result\QueryOutput;
use AsyncAws\DynamoDb\Result\ScanOutput;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;

/**
 * The read side behind {@see Client}: key reads, searches and counts. {@see ReadRequestFactory} builds what is sent;
 * this sends it, page by page and round by round.
 *
 * @internal
 */
class ReaderClient
{
    private const int BATCH_GET_LIMIT = 100;

    /**
     * @internal
     */
    public function __construct(
        protected readonly DynamoDbClient $client,
        protected readonly Serializer $serializer,
        protected readonly ReadRequestFactory $requests,
    ) {
    }

    /**
     * Reads the keys of one or more entity classes: a single key is a `GetItem`, several are `BatchGetItem`s
     * chunked at 100 keys, re-requesting whatever DynamoDB reports back as unprocessed after a growing pause.
     *
     * @template Entity of AbstractEntity
     *
     * @param GetInput<Entity> $input
     *
     * @throws UnknownEntityDefinitionException
     * @throws DALException if a key does not serialize, or a stored item does not deserialize
     * @throws AsyncAwsException if a request to DynamoDB fails
     *
     * @return \Generator<int, Entity>
     */
    public function get(GetInput $input): \Generator
    {
        yield from $this->read($this->requests->get($input));
    }

    /**
     * Reads the given entities back into themselves, the same way {@see get()} reads keys. An entity whose row
     * no longer exists is left as is.
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
        // Rows land in the entities themselves, only the read needs driving.
        foreach ($this->read($this->requests->refresh($input)) as $_) {
        }
    }

    /**
     * Streams the matches, each keyed by its raw start key: the item's table key attributes, plus the index key
     * attributes for a GSI query — exactly what DynamoDB takes as `ExclusiveStartKey` to resume after it.
     *
     * A backward cursor reads the query in reverse from its key, so the stream runs towards the start.
     *
     * @template Entity of AbstractEntity
     *
     * @param ScanInput<Entity>|QueryInput<Entity> $query
     *
     * @throws UnknownEntityDefinitionException
     * @throws UnknownIndexException
     * @throws InvalidKeyConditionException
     * @throws InvalidCursorException
     * @throws DALException if the query does not compile, or an item does not deserialize
     * @throws AsyncAwsException if a request to DynamoDB fails
     *
     * @return \Generator<array<string, AttributeValue>, Entity>
     */
    public function search(ScanInput|QueryInput $query): \Generator
    {
        $search = $this->requests->search($query);

        foreach ($this->pages($search->input) as $page) {
            foreach ($page->getItems(true) as $item) {
                $entity = $this->serializer->deserialize($search->definition, $item);
                if ($entity !== null) {
                    yield array_intersect_key($item, $search->startKeyFields) => $entity;
                }
            }
        }
    }

    /**
     * Counts matches via `Select=COUNT`, summing the per-page counts (async-aws does not accumulate them).
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
        $count = 0;
        foreach ($this->pages($this->requests->count($query)) as $page) {
            $count += $page->getCount() ?? 0;
        }

        return $count;
    }

    /**
     * The shared read path of {@see get()} and {@see refresh()}: one key is a `GetItem`, several are `BatchGetItem`s.
     *
     * @template Entity of AbstractEntity
     *
     * @param KeyRead<Entity> $keys
     *
     * @throws DALException if a stored item does not deserialize
     * @throws AsyncAwsException if a request to DynamoDB fails
     *
     * @return \Generator<int, Entity>
     */
    private function read(KeyRead $keys): \Generator
    {
        $index = 0;

        $request = $keys->getItemRequest();
        if ($request !== null) {
            foreach ($this->deserialize($keys, [$request['TableName'] => [$this->client->getItem($request)->getItem()]]) as $entity) {
                yield $index++ => $entity;
            }

            return;
        }

        foreach ($keys->chunks(self::BATCH_GET_LIMIT) as $chunk) {
            $requestItems = $chunk->requestItems();

            // BatchGetItem may return only part of a chunk, under throttling or past 16 MB; retry the leftover keys.
            for ($round = 1; $requestItems !== []; ++$round) {
                $output = $this->client->batchGetItem(['RequestItems' => $requestItems]);

                foreach ($this->deserialize($chunk, $output->getResponses()) as $entity) {
                    yield $index++ => $entity;
                }

                $requestItems = $output->getUnprocessedKeys();
                if ($requestItems !== []) {
                    Backoff::wait($round);
                }
            }
        }
    }

    /**
     * Reads each item into the entities of the key it answers, or into a new one where the key has none. An item
     * that answers no key of the read is skipped.
     *
     * @template Entity of AbstractEntity
     *
     * @param KeyRead<Entity> $keys
     * @param array<string, array<array<string, AttributeValue>>> $items - by physical table, as `BatchGetItem` answers
     *
     * @throws DALException if an item does not deserialize
     *
     * @return \Generator<int, Entity>
     */
    private function deserialize(KeyRead $keys, array $items): \Generator
    {
        foreach ($items as $table => $tableItems) {
            foreach ($tableItems as $item) {
                $key = $keys->keyOf($table, $item);
                if ($key === null) {
                    continue;
                }

                $targets = $keys->targets($key);
                foreach ($targets === [] ? [null] : $targets as $target) {
                    $entity = $this->serializer->deserialize($key->definition, $item, $target);
                    if ($entity !== null) {
                        yield $entity;
                    }
                }
            }
        }
    }

    /**
     * Page by page rather than via async-aws' getItems(), which requests the next page before handing out the
     * current one's items: once the consumer stops, no further page is read.
     *
     * @throws AsyncAwsException if a request to DynamoDB fails
     *
     * @return \Generator<int, QueryOutput|ScanOutput>
     */
    private function pages(DynamoDbQueryInput|DynamoDbScanInput $input): \Generator
    {
        while (true) {
            $page = $input instanceof DynamoDbScanInput ? $this->client->scan($input) : $this->client->query($input);

            yield $page;

            $lastEvaluatedKey = $page->getLastEvaluatedKey();
            if ($lastEvaluatedKey === []) {
                return;
            }

            $input = clone $input;
            $input->setExclusiveStartKey($lastEvaluatedKey);
        }
    }
}
