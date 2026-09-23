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
        foreach (array_chunk($pairs, self::BATCH_GET_LIMIT) as $chunk) {
            /** @var array<string, array{Keys: list<array<string, AttributeValue>>, ConsistentRead: ?bool}> $requestItems */
            $requestItems = [];
            foreach ($chunk as [$physicalTable, $keyFields]) {
                $requestItems[$physicalTable] ??= ['Keys' => [], 'ConsistentRead' => $input->consistentRead];
                $requestItems[$physicalTable]['Keys'][] = $keyFields;
            }

            // BatchGetItem may return only part of a chunk under throttling; retry the leftover keys.
            while ($requestItems !== []) {
                $output = $this->client->batchGetItem(['RequestItems' => $requestItems]);
                $responses = $output->getResponses();

                // Responses come back keyed by physical table; walking the requested definitions instead of
                // the raw response deserializes every item with the definition its keys were built from.
                foreach ($definitions as $physicalTable => $definition) {
                    foreach ($responses[$physicalTable] ?? [] as $item) {
                        $entity = $this->serializer->deserialize($definition, $item);
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
