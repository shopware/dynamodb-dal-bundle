<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Client\Read;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Client\Cursor;
use Shopware\DynamodbDalBundle\Client\Input\GetInput;
use Shopware\DynamodbDalBundle\Client\Input\QueryInput;
use Shopware\DynamodbDalBundle\Client\Input\RefreshInput;
use Shopware\DynamodbDalBundle\Client\Input\ScanInput;
use Shopware\DynamodbDalBundle\Client\Output\SearchOutput;
use Shopware\DynamodbDalBundle\Exception\DALException;
use Shopware\DynamodbDalBundle\Exception\InvalidCursorException;
use Shopware\DynamodbDalBundle\Exception\InvalidKeyConditionException;
use Shopware\DynamodbDalBundle\Exception\UnknownEntityDefinitionException;
use Shopware\DynamodbDalBundle\Exception\UnknownIndexException;
use Shopware\DynamodbDalBundle\Expression\ExpressionCompiledResult;
use Shopware\DynamodbDalBundle\Expression\FilterCompiler;
use Shopware\DynamodbDalBundle\Definition\AttributeType;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\EntityDefinitionRegistry;
use Shopware\DynamodbDalBundle\Definition\IndexSchema;
use Shopware\DynamodbDalBundle\Serializer\Serializer;
use AsyncAws\DynamoDb\Enum\Select;
use AsyncAws\DynamoDb\Input\QueryInput as DynamoDbQueryInput;
use AsyncAws\DynamoDb\Input\ScanInput as DynamoDbScanInput;

/**
 * Turns read inputs into the requests DynamoDB takes: keys into a {@see KeyRead}, and a scan or query into a
 * {@see PreparedSearch} or the input of its count.
 *
 * @internal
 */
final readonly class ReadRequestFactory
{
    /**
     * @internal
     */
    public function __construct(
        private Serializer $serializer,
        private FilterCompiler $filterCompiler,
        private EntityDefinitionRegistry $definitionRegistry,
    ) {
    }

    /**
     * Each key is read into a new entity.
     *
     * @template Entity of AbstractEntity
     *
     * @param GetInput<Entity> $input
     *
     * @throws UnknownEntityDefinitionException
     * @throws DALException if a key does not serialize
     *
     * @return KeyRead<Entity>
     */
    public function get(GetInput $input): KeyRead
    {
        $keys = [];
        foreach ($input->keys as $key) {
            $definition = $this->definitionRegistry->getByEntityClass($key->class);

            $keys[] = [$this->serializer->serializeKey($definition, $key), null];
        }

        return new KeyRead($input->consistentRead, $keys);
    }

    /**
     * Each entity is read back into itself, by its own key.
     *
     * @template Entity of AbstractEntity
     *
     * @param RefreshInput<Entity> $input
     *
     * @throws UnknownEntityDefinitionException
     * @throws DALException if a key does not serialize
     *
     * @return KeyRead<Entity>
     */
    public function refresh(RefreshInput $input): KeyRead
    {
        $keys = [];
        foreach ($input->entities as $entity) {
            $definition = $this->definitionRegistry->getByEntityClass($entity::class);

            $keys[] = [$this->serializer->serializeKey($definition, $entity), $entity];
        }

        return new KeyRead($input->consistentRead, $keys);
    }

    /**
     * The first page resumes from the search's cursor, and a backward cursor reads the query in reverse from its key.
     * A cursor that no search of the table or index can resume from is refused before it is sent: see
     * {@see checkCursor()}. One of another partition, or outside the query's range key condition, is left to DynamoDB,
     * which refuses it as a start key.
     *
     * @template Entity of AbstractEntity
     *
     * @param ScanInput<Entity>|QueryInput<Entity> $query
     *
     * @throws UnknownEntityDefinitionException
     * @throws UnknownIndexException
     * @throws InvalidCursorException
     * @throws InvalidKeyConditionException
     * @throws DALException if the filter or key condition does not compile
     *
     * @return PreparedSearch<Entity>
     */
    public function search(ScanInput|QueryInput $query): PreparedSearch
    {
        $definition = $this->definitionRegistry->getByEntityClass($query->class);
        $index = $this->index($definition, $query);

        $startKeyFields = array_fill_keys([...$definition->getKeySchema()->getFields(), ...$index?->keySchema->getFields() ?? []], true);

        $cursor = $query->cursor !== null ? Cursor::decode($query->cursor) : null;
        if ($cursor !== null) {
            $this->checkCursor($definition, $query, $cursor, $startKeyFields);
        }

        $input = $this->input($definition, $query, $index, $cursor !== null && $cursor->backward);

        if ($cursor !== null) {
            $input->setExclusiveStartKey($cursor->key);
        }

        if ($query->filter === null && $query->limit !== null) {
            // One past the limit, so page() can tell whether another page follows. DynamoDB filters after
            // applying `Limit`, so a filtered search reads full pages instead.
            $input->setLimit(max(1, $query->limit) + 1);
        }

        return new PreparedSearch($definition, $input, $startKeyFields);
    }

    /**
     * Counts with `Select=COUNT`, from the start and in full pages: the search's cursor and limit only apply to the
     * items {@see SearchOutput} hands out.
     *
     * @param ScanInput<AbstractEntity>|QueryInput<AbstractEntity> $query
     *
     * @throws UnknownEntityDefinitionException
     * @throws UnknownIndexException
     * @throws InvalidKeyConditionException
     * @throws DALException if the filter or key condition does not compile
     */
    public function count(ScanInput|QueryInput $query): DynamoDbQueryInput|DynamoDbScanInput
    {
        $definition = $this->definitionRegistry->getByEntityClass($query->class);

        $input = $this->input($definition, $query, $this->index($definition, $query));
        $input->setSelect(Select::COUNT);

        return $input;
    }

    /**
     * Refuses a cursor that no search of the table or index can resume from: one whose key is not the table's or
     * index's, or holds a value of another type than its field is stored as. A scan cannot be read backward. A value
     * no key of any table can have, such as an empty one, {@see Cursor::decode()} refuses already.
     *
     * @param ScanInput<AbstractEntity>|QueryInput<AbstractEntity> $query
     * @param array<string, true> $startKeyFields - the key attributes a cursor of the search holds
     *
     * @throws InvalidCursorException
     */
    private function checkCursor(EntityDefinition $definition, ScanInput|QueryInput $query, Cursor $cursor, array $startKeyFields): void
    {
        if (array_diff_key($cursor->key, $startKeyFields) !== [] || array_diff_key($startKeyFields, $cursor->key) !== []) {
            throw new InvalidCursorException('its key does not match the table or index queried');
        }

        if ($cursor->backward && $query instanceof ScanInput) {
            throw new InvalidCursorException('a scan cannot be read backward');
        }

        foreach ($cursor->key as $name => $value) {
            // Cursor::decode() takes a string, number or binary only, and each tells its type
            $type = AttributeType::tryFromAttributeValue($value) ?? AttributeType::Binary;

            $stored = $definition->getFieldDefinition($name)?->getAttributeType();
            if ($stored !== null && $stored !== $type) {
                throw new InvalidCursorException(\sprintf('key attribute "%s" is of type %s, where its field is stored as %s', $name, $type->value, $stored->value));
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
     * The async-aws `query`/`scan` input of the search's first page; only a {@see QueryInput} adds the key condition,
     * sort direction and index name.
     *
     * @param ScanInput<AbstractEntity>|QueryInput<AbstractEntity> $search
     * @param ?IndexSchema $index - the index a query names, as {@see index()} looks it up
     * @param bool $backward - flips the sort direction, for a backward cursor
     *
     * @throws InvalidKeyConditionException
     * @throws DALException if the filter or key condition does not compile
     */
    private function input(EntityDefinition $definition, ScanInput|QueryInput $search, ?IndexSchema $index, bool $backward = false): DynamoDbQueryInput|DynamoDbScanInput
    {
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

        if ($filterResult->names !== []) {
            $input->setExpressionAttributeNames($filterResult->names);
        }

        if ($filterResult->values !== []) {
            $input->setExpressionAttributeValues($filterResult->values);
        }

        return $input;
    }
}
