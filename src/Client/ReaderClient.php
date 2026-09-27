<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Client;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Client\Input\GetInput;
use Shopware\DynamodbDalBundle\Client\Input\QueryInput;
use Shopware\DynamodbDalBundle\Client\Input\RefreshInput;
use Shopware\DynamodbDalBundle\Client\Input\ScanInput;
use Shopware\DynamodbDalBundle\Exception\DALException;
use Shopware\DynamodbDalBundle\Exception\InvalidCursorException;
use Shopware\DynamodbDalBundle\Exception\InvalidKeyConditionException;
use Shopware\DynamodbDalBundle\Exception\UnknownEntityDefinitionException;
use Shopware\DynamodbDalBundle\Exception\UnknownIndexException;
use Shopware\DynamodbDalBundle\Expression\ExpressionCompiledResult;
use Shopware\DynamodbDalBundle\Expression\FilterCompiler;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\EntityDefinitionRegistry;
use Shopware\DynamodbDalBundle\Definition\IndexSchema;
use Shopware\DynamodbDalBundle\Serializer\Serializer;
use AsyncAws\Core\Exception\Exception as AsyncAwsException;
use AsyncAws\DynamoDb\DynamoDbClient;
use AsyncAws\DynamoDb\Enum\Select;
use AsyncAws\DynamoDb\Input\QueryInput as DynamoDbQueryInput;
use AsyncAws\DynamoDb\Input\ScanInput as DynamoDbScanInput;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;

/**
 * The read side behind {@see Client}: key reads, searches and counts.
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
        protected readonly FilterCompiler $filterCompiler,
        protected readonly EntityDefinitionRegistry $definitionRegistry,
    ) {
    }

    /**
     * Reads the keys of one or more entity classes: a single key is a `GetItem`, several are `BatchGetItem`s
     * chunked at 100 keys, re-requesting whatever DynamoDB reports back as unprocessed.
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
        $requests = [];
        foreach ($input->keys as $key) {
            $definition = $this->definitionRegistry->getByEntityClass($key->class);

            $requests[] = [$definition, $this->serializer->serializeKey($definition, $key), null];
        }

        yield from $this->read($requests, $input->consistentRead);
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
        $requests = [];
        foreach ($input->entities as $entity) {
            $definition = $this->definitionRegistry->getByEntityClass($entity::class);

            $requests[] = [$definition, $this->serializer->serializeKey($definition, $entity), $entity];
        }

        // Rows land in the entities themselves, only the read needs driving.
        foreach ($this->read($requests, $input->consistentRead) as $_) {
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
        $definition = $this->definitionRegistry->getByEntityClass($query->class);
        $index = $this->index($definition, $query);

        $keyFields = array_fill_keys([...$definition->getKeySchema()->getFields(), ...$index?->keySchema->getFields() ?? []], true);

        $cursor = $query->cursor !== null ? Cursor::decode($query->cursor) : null;
        if ($cursor !== null && (array_diff_key($cursor->key, $keyFields) !== [] || array_diff_key($keyFields, $cursor->key) !== [])) {
            throw new InvalidCursorException('its key does not match the table or index queried');
        }

        if ($cursor !== null && $cursor->backward && $query instanceof ScanInput) {
            throw new InvalidCursorException('a scan cannot be read backward');
        }

        $input = $this->createSearchInput($definition, $query, $index, $cursor);

        if ($query->filter === null && $query->limit !== null) {
            // One past the limit, so page() can tell whether another page follows. DynamoDB filters after
            // applying `Limit`, so a filtered search reads full pages instead.
            $input->setLimit(max(1, $query->limit) + 1);
        }

        // Page by page rather than via async-aws' getItems(), which requests the next page before handing out
        // the current one's items: once the consumer stops, no further page is read.
        while (true) {
            $output = $input instanceof DynamoDbScanInput ? $this->client->scan($input) : $this->client->query($input);

            foreach ($output->getItems(true) as $item) {
                $entity = $this->serializer->deserialize($definition, $item);
                if ($entity !== null) {
                    yield array_intersect_key($item, $keyFields) => $entity;
                }
            }

            $lastEvaluatedKey = $output->getLastEvaluatedKey();
            if ($lastEvaluatedKey === []) {
                return;
            }

            $input = clone $input;
            $input->setExclusiveStartKey($lastEvaluatedKey);
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
        $definition = $this->definitionRegistry->getByEntityClass($query->class);
        $index = $this->index($definition, $query);

        $count = 0;
        $startKey = null;

        do {
            $input = $this->createSearchInput($definition, $query, $index, $startKey);
            $input->setSelect(Select::COUNT);

            $output = $input instanceof DynamoDbScanInput ? $this->client->scan($input) : $this->client->query($input);

            $count += $output->getCount() ?? 0;

            $startKey = $output->getLastEvaluatedKey() ?: null;
        } while ($startKey !== null);

        return $count;
    }

    /**
     * The shared read path of {@see get()} and {@see refresh()}: a single key is a `GetItem`, several are
     * `BatchGetItem`s. A key given more than once is read once, since `BatchGetItem` refuses a request that lists it
     * twice. Its row is deserialized into every target given for it, or into one new entity where it has none.
     *
     * @template Entity of AbstractEntity
     *
     * @param list<array{EntityDefinition<Entity>, array<string, AttributeValue>, ?Entity}> $requests - definition, serialized key and target
     *
     * @throws DALException if a key does not serialize, or a stored item does not deserialize
     * @throws AsyncAwsException if a request to DynamoDB fails
     *
     * @return \Generator<int, Entity>
     */
    private function read(array $requests, bool $consistentRead): \Generator
    {
        // Keyed by physical table, as BatchGetItem answers under it
        $definitions = [];
        // Every distinct key once, as [physicalTable, key hash, key map], flat, as the 100-key cap counts the keys of
        // every table in a request
        $pairs = [];
        // BatchGetItem answers in no order and does not echo the request, so a row finds its targets by its key
        /** @var array<string, array<string, list<Entity>>> $targets - physical table, then key hash */
        $targets = [];
        foreach ($requests as [$definition, $key, $target]) {
            $table = $definition->getTable();
            $hash = $this->serializer->hashKey($definition, $key);
            $definitions[$table] = $definition;

            if (!isset($targets[$table][$hash])) {
                $pairs[] = [$table, $hash, $key];
                $targets[$table][$hash] = [];
            }

            if ($target !== null && !\in_array($target, $targets[$table][$hash], true)) {
                $targets[$table][$hash][] = $target;
            }
        }

        $idx = 0;

        if (\count($pairs) === 1) {
            [$table, $hash, $key] = $pairs[0];

            $output = $this->client->getItem([
                'TableName' => $table,
                'Key' => $key,
                'ConsistentRead' => $consistentRead,
            ]);

            foreach ($this->deserializeInto($definitions[$table], $output->getItem(), $targets[$table][$hash]) as $entity) {
                yield $idx++ => $entity;
            }

            return;
        }

        foreach (array_chunk($pairs, self::BATCH_GET_LIMIT) as $chunk) {
            /** @var array<string, array{Keys: list<array<string, AttributeValue>>, ConsistentRead: bool}> $requestItems */
            $requestItems = [];
            foreach ($chunk as [$physicalTable, , $keyFields]) {
                $requestItems[$physicalTable] ??= ['Keys' => [], 'ConsistentRead' => $consistentRead];
                $requestItems[$physicalTable]['Keys'][] = $keyFields;
            }

            // BatchGetItem may return only part of a chunk under throttling; retry the leftover keys.
            while ($requestItems !== []) {
                $output = $this->client->batchGetItem(['RequestItems' => $requestItems]);
                $responses = $output->getResponses();

                // Walking the requested definitions instead of the raw response deserializes every item with the
                // definition its keys were built from.
                foreach ($definitions as $physicalTable => $definition) {
                    foreach ($responses[$physicalTable] ?? [] as $item) {
                        $rowTargets = $targets[$physicalTable][$this->serializer->hashKey($definition, $item)] ?? [];

                        foreach ($this->deserializeInto($definition, $item, $rowTargets) as $entity) {
                            yield $idx++ => $entity;
                        }
                    }
                }

                $requestItems = $output->getUnprocessedKeys();
            }
        }
    }

    /**
     * Deserializes a row into each of its targets, or into one new entity where it has none.
     *
     * @template Entity of AbstractEntity
     *
     * @param EntityDefinition<Entity> $definition
     * @param array<string, AttributeValue> $item - empty where no row has the key
     * @param list<Entity> $targets
     *
     * @throws DALException if the item does not deserialize
     *
     * @return \Generator<int, Entity>
     */
    private function deserializeInto(EntityDefinition $definition, array $item, array $targets): \Generator
    {
        foreach ($targets === [] ? [null] : $targets as $target) {
            $entity = $this->serializer->deserialize($definition, $item, $target);
            if ($entity !== null) {
                yield $entity;
            }
        }
    }

    /**
     * The index a query names, as `#[Table]` declares it; `null` for a scan and a query of the table.
     *
     * @param ScanInput<AbstractEntity>|QueryInput<AbstractEntity> $search
     *
     * @throws UnknownIndexException
     */
    private function index(EntityDefinition $definition, ScanInput|QueryInput $search): ?IndexSchema
    {
        if (!$search instanceof QueryInput || $search->index === null) {
            return null;
        }

        return $definition->getIndex($search->index) ?? throw new UnknownIndexException($definition, $search->index);
    }

    /**
     * Builds (but does not run) the async-aws `query`/`scan` input; only a {@see QueryInput} adds the key
     * condition, sort direction and index name.
     *
     * @param ScanInput<AbstractEntity>|QueryInput<AbstractEntity> $search
     * @param ?IndexSchema $index - the index a query names, as {@see index()} looks it up
     * @param Cursor|array<string, AttributeValue>|null $start - a resume position; a backward {@see Cursor} flips the sort direction
     *
     * @throws InvalidKeyConditionException
     * @throws DALException if the filter or key condition does not compile
     */
    private function createSearchInput(EntityDefinition $definition, ScanInput|QueryInput $search, ?IndexSchema $index, Cursor|array|null $start = null): DynamoDbQueryInput|DynamoDbScanInput
    {
        $backward = $start instanceof Cursor && $start->backward;
        $exclusiveStartKey = $start instanceof Cursor ? $start->key : $start;

        $filterResult = $search->filter !== null ? $this->filterCompiler->filter($definition, $search->filter) : new ExpressionCompiledResult();

        if ($search instanceof QueryInput) {
            $keyResult = $this->filterCompiler->keyCondition($definition, $search->keyCondition, $index);
            $filterResult = $filterResult->merge($keyResult);

            $input = new DynamoDbQueryInput();
            $input->setKeyConditionExpression($keyResult->expression);
            $input->setScanIndexForward($search->forward !== $backward);
            $input->setIndexName($search->index);
        } else {
            $input = new DynamoDbScanInput();
        }

        $input->setTableName($definition->getTable());
        $input->setFilterExpression($filterResult->expression);
        $input->setConsistentRead($search->consistentRead);

        if ($exclusiveStartKey !== null && $exclusiveStartKey !== []) {
            $input->setExclusiveStartKey($exclusiveStartKey);
        }

        if ($filterResult->names !== []) {
            $input->setExpressionAttributeNames($filterResult->names);
        }

        if ($filterResult->values !== []) {
            $input->setExpressionAttributeValues($filterResult->values);
        }

        return $input;
    }
}
