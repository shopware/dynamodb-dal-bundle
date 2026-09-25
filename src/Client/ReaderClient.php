<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Client;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Client\Input\GetInput;
use Shopware\DynamodbDalBundle\Client\Input\QueryInput;
use Shopware\DynamodbDalBundle\Client\Input\RefreshInput;
use Shopware\DynamodbDalBundle\Client\Input\ScanInput;
use Shopware\DynamodbDalBundle\Exception\DALException;
use Shopware\DynamodbDalBundle\Exception\InvalidCursorException;
use Shopware\DynamodbDalBundle\Exception\UnknownEntityDefinitionException;
use Shopware\DynamodbDalBundle\Expression\ExpressionCompiledResult;
use Shopware\DynamodbDalBundle\Expression\ExpressionCompiler;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\EntityDefinitionRegistry;
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
        protected readonly ExpressionCompiler $expressionCompiler,
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
     * @throws InvalidCursorException
     * @throws DALException if the query does not compile, or an item does not deserialize
     * @throws AsyncAwsException if a request to DynamoDB fails
     *
     * @return \Generator<array<string, AttributeValue>, Entity>
     */
    public function search(ScanInput|QueryInput $query): \Generator
    {
        $definition = $this->definitionRegistry->getByEntityClass($query->class);

        $keyFields = $definition->getKeySchema()->getFields();
        if ($query instanceof QueryInput && $query->index !== null) {
            $keyFields = [...$keyFields, ...$definition->getIndex($query->index)?->keySchema->getFields() ?? []];
        }
        $keyFields = array_fill_keys($keyFields, true);

        $cursor = $query->cursor !== null ? Cursor::decode($query->cursor) : null;
        if ($cursor !== null && (array_diff_key($cursor->key, $keyFields) !== [] || array_diff_key($keyFields, $cursor->key) !== [])) {
            throw new InvalidCursorException('its key does not match the table or index queried');
        }

        if ($cursor !== null && $cursor->backward && $query instanceof ScanInput) {
            throw new InvalidCursorException('a scan cannot be read backward');
        }

        $input = $this->createSearchInput($definition, $query, $cursor);

        if ($query->filter === null && $query->limit !== null) {
            // One past the limit, so page() can tell whether another page follows. DynamoDB filters after
            // applying `Limit`, so a filtered search reads full pages instead. A limit below 1 counts as 1,
            // as in SearchOutput.
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
     * @throws DALException if the query does not compile
     * @throws AsyncAwsException if a request to DynamoDB fails
     */
    public function count(ScanInput|QueryInput $query): int
    {
        $definition = $this->definitionRegistry->getByEntityClass($query->class);

        $count = 0;
        $startKey = null;

        do {
            $input = $this->createSearchInput($definition, $query, $startKey);
            $input->setSelect(Select::COUNT);

            $output = $input instanceof DynamoDbScanInput ? $this->client->scan($input) : $this->client->query($input);

            $count += $output->getCount() ?? 0;

            $startKey = $output->getLastEvaluatedKey() ?: null;
        } while ($startKey !== null);

        return $count;
    }

    /**
     * The shared read path of {@see get()} and {@see refresh()}: a single key is a `GetItem`, several are
     * `BatchGetItem`s. Each row is deserialized into its request's target, or into a new entity if it has none.
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
        if (\count($requests) === 1) {
            $output = $this->client->getItem([
                'TableName' => $requests[0][0]->getTable(),
                'Key' => $requests[0][1],
                'ConsistentRead' => $consistentRead,
            ]);

            $entity = $this->serializer->deserialize($requests[0][0], $output->getItem(), $requests[0][2]);

            if ($entity !== null) {
                yield 0 => $entity;
            }

            return;
        }

        // Keyed by physical table, as BatchGetItem answers under it
        $definitions = [];
        // [physicalTable, key map] pairs, flat, as the 100-key cap counts the keys of every table in a request
        $pairs = [];
        // BatchGetItem answers in no order and does not echo the request, so a row finds its target by its key
        /** @var array<string, array<string, Entity>> $targets - physical table, then key */
        $targets = [];
        foreach ($requests as [$definition, $key, $target]) {
            $table = $definition->getTable();
            $definitions[$table] = $definition;
            $pairs[] = [$table, $key];

            if ($target !== null) {
                $targets[$table][$this->serializer->hashKey($definition, $key)] = $target;
            }
        }

        $idx = 0;
        foreach (array_chunk($pairs, self::BATCH_GET_LIMIT) as $chunk) {
            /** @var array<string, array{Keys: list<array<string, AttributeValue>>, ConsistentRead: bool}> $requestItems */
            $requestItems = [];
            foreach ($chunk as [$physicalTable, $keyFields]) {
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
                        $target = $targets !== [] ? $targets[$physicalTable][$this->serializer->hashKey($definition, $item)] ?? null : null;

                        $entity = $this->serializer->deserialize($definition, $item, $target);
                        if ($entity !== null) {
                            yield $idx++ => $entity;
                        }
                    }
                }

                $requestItems = $output->getUnprocessedKeys();
            }
        }
    }

    /**
     * Builds (but does not run) the async-aws `query`/`scan` input; only a {@see QueryInput} adds the key
     * condition, sort direction and index name.
     *
     * @param ScanInput<AbstractEntity>|QueryInput<AbstractEntity> $search
     * @param Cursor|array<string, AttributeValue>|null $start - a resume position; a backward {@see Cursor} flips the sort direction
     *
     * @throws DALException if the filter or key condition does not compile
     */
    private function createSearchInput(EntityDefinition $definition, ScanInput|QueryInput $search, Cursor|array|null $start = null): DynamoDbQueryInput|DynamoDbScanInput
    {
        $backward = $start instanceof Cursor && $start->backward;
        $exclusiveStartKey = $start instanceof Cursor ? $start->key : $start;

        $filterResult = $search->filter !== null ? $this->expressionCompiler->compileFilter($definition, $search->filter) : new ExpressionCompiledResult();

        if ($search instanceof QueryInput) {
            $keyResult = $this->expressionCompiler->compileCondition($definition, $search->keyCondition);
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
