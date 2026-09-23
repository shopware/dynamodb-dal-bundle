<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Client;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Client\Input\GetInput;
use Shopware\DynamodbDalBundle\Client\Input\QueryInput;
use Shopware\DynamodbDalBundle\Client\Input\ScanInput;
use Shopware\DynamodbDalBundle\Expression\ExpressionCompiledResult;
use Shopware\DynamodbDalBundle\Expression\ExpressionCompiler;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\EntityDefinitionRegistry;
use Shopware\DynamodbDalBundle\Serializer\Serializer;
use AsyncAws\DynamoDb\DynamoDbClient;
use AsyncAws\DynamoDb\Enum\Select;
use AsyncAws\DynamoDb\Input\QueryInput as DynamoDbQueryInput;
use AsyncAws\DynamoDb\Input\ScanInput as DynamoDbScanInput;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;

/**
 * Client to create generators for reading entities from DynamoDB tables
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
     * @return \Generator<int, Entity>
     */
    public function get(GetInput $input): \Generator
    {
        // Resolve each requested class once, indexed by the physical table a response comes back under, and
        // flatten to [physicalTable, key map] pairs so the 100-item cap is honoured across all tables.
        $definitions = [];
        $pairs = [];
        foreach ($input->keysByClass as $class => $keys) {
            $definition = $this->definitionRegistry->getByEntityClass($class);
            $definitions[$definition->getTable()] = $definition;

            foreach ($keys as $key) {
                $pairs[] = [$definition->getTable(), $this->serializer->serializeKey($definition, $key)];
            }
        }

        if (\count($pairs) === 1) {
            if ($entity = $this->getSingle($definitions[$pairs[0][0]], $pairs[0][1], $input->consistentRead)) {
                yield 0 => $entity;
            }

            return;
        }

        $idx = 0;
        foreach ($this->batchGet($definitions, $pairs, $input->consistentRead) as [$definition, $item]) {
            $entity = $this->serializer->deserialize($definition, $item);
            if ($entity !== null) {
                yield $idx++ => $entity;
            }
        }
    }

    /**
     * @TODO should become part of GetInput
     * 
     * Reads the given entities back into themselves.
     *
     * @param array<class-string<AbstractEntity>, list<AbstractEntity>> $entitiesByClass - same shape as {@see GetInput::$keysByClass}, by instance
     */
    public function refresh(array $entitiesByClass): void
    {
        // BatchGetItem answers per table, in no order, and does not echo the request, so every entity is
        // indexed by its key up front to match a row back to the instance it belongs to.
        $definitions = [];
        $pairs = [];
        /** @var array<string, array<string, AbstractEntity>> $entities - physical table, then key */
        $entities = [];
        foreach ($entitiesByClass as $class => $classEntities) {
            $definition = $this->definitionRegistry->getByEntityClass($class);
            $table = $definition->getTable();
            $definitions[$table] = $definition;

            foreach ($classEntities as $entity) {
                $key = $this->serializer->serializeKey($definition, $entity);

                $entities[$table][$this->serializer->hashKey($definition, $key)] = $entity;
                $pairs[] = [$table, $key];
            }
        }

        // ensure consistent read, because a write may have been to a replica that has not yet propagated to the
        foreach ($this->batchGet($definitions, $pairs, true) as [$definition, $item]) {
            $entity = $entities[$definition->getTable()][$this->serializer->hashKey($definition, $item)] ?? null;

            // A row deleted between the write and this read comes back as nothing at all, existing entity stays as is
            if ($entity !== null) {
                $this->serializer->deserialize($definition, $item, $entity);
            }
        }
    }

    /**
     * @template Entity of AbstractEntity
     *
     * @param EntityDefinition<Entity> $definition
     *
     * @return \Generator<int, Entity>
     */
    public function search(EntityDefinition $definition, ScanInput|QueryInput $query): \Generator
    {
        $exclusiveStartKey = $query->cursor !== null ? $this->serializer->serializeCursor($definition, $query->cursor) : null;
        $input = $this->createSearchInput($definition, $query, $exclusiveStartKey);
        $output = $input instanceof DynamoDbScanInput ? $this->client->scan($input) : $this->client->query($input);

        $idx = 0;
        foreach ($output->getItems() as $item) {
            $entity = $this->serializer->deserialize($definition, $item);
            if ($entity !== null) {
                yield $idx => $entity;
                ++$idx;
            }
        }
    }

    /**
     * Counts matches via `Select=COUNT`, summing the per-page counts (async-aws does not accumulate them).
     */
    public function count(EntityDefinition $definition, ScanInput|QueryInput $query): int
    {
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
     * @template Entity of AbstractEntity
     *
     * @param EntityDefinition<Entity> $definition
     * @param array<string, AttributeValue> $key
     *
     * @return ?Entity
     */
    protected function getSingle(EntityDefinition $definition, array $key, ?bool $consistentRead): ?AbstractEntity
    {
        $output = $this->client->getItem([
            'TableName' => $definition->getTable(),
            'Key' => $key,
            'ConsistentRead' => $consistentRead,
        ]);

        return $this->serializer->deserialize($definition, $output->getItem());
    }

    /**
     * Runs the keys as `BatchGetItem`s chunked at 100, re-requesting whatever DynamoDB reports back as
     * unprocessed, and yields each returned item with the definition its key was built from. Responses are
     * keyed by physical table, so walking the requested definitions is what ties an item to its definition.
     *
     * @template Entity of AbstractEntity
     *
     * @param array<string, EntityDefinition<Entity>> $definitions - keyed by physical table
     * @param list<array{string, array<string, AttributeValue>}> $pairs - physical table and serialized key
     *
     * @return \Generator<int, array{EntityDefinition<Entity>, array<string, AttributeValue>}>
     */
    private function batchGet(array $definitions, array $pairs, ?bool $consistentRead): \Generator
    {
        foreach (array_chunk($pairs, self::BATCH_GET_LIMIT) as $chunk) {
            /** @var array<string, array{Keys: list<array<string, AttributeValue>>, ConsistentRead: ?bool}> $requestItems */
            $requestItems = [];
            foreach ($chunk as [$physicalTable, $keyFields]) {
                $requestItems[$physicalTable] ??= ['Keys' => [], 'ConsistentRead' => $consistentRead];
                $requestItems[$physicalTable]['Keys'][] = $keyFields;
            }

            // BatchGetItem may return only part of a chunk under throttling; retry the leftover keys.
            while ($requestItems !== []) {
                $output = $this->client->batchGetItem(['RequestItems' => $requestItems]);
                $responses = $output->getResponses();

                foreach ($definitions as $physicalTable => $definition) {
                    foreach ($responses[$physicalTable] ?? [] as $item) {
                        yield [$definition, $item];
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
     * @param array<string, AttributeValue>|null $exclusiveStartKey
     */
    private function createSearchInput(EntityDefinition $definition, ScanInput|QueryInput $search, ?array $exclusiveStartKey = null): DynamoDbQueryInput|DynamoDbScanInput
    {
        $filterResult = $search->filter !== null ? $this->expressionCompiler->compile($definition, $search->filter) : new ExpressionCompiledResult();

        if ($search instanceof QueryInput) {
            $keyResult = $this->expressionCompiler->compile($definition, $search->keyCondition);
            $filterResult = $filterResult->merge($keyResult);

            $input = new DynamoDbQueryInput();
            $input->setKeyConditionExpression($keyResult->expression);
            $input->setScanIndexForward($search->forward);
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

        if ($search->filter === null && $search->limit !== null) {
            // always over-fetch to keep pagination logic working
            $input->setLimit($search->limit + 1);
        }

        return $input;
    }
}
