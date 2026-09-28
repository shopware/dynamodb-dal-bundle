<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Client\Read;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Serializer\SerializedKeyResult;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;

/**
 * The keys of a read by key, each once, and the entities the item of each is read into.
 *
 * `BatchGetItem` refuses a request that names a key twice, so a key given more than once is read once, into every
 * entity given for it. It answers in no order and without the request, so an item finds its key by its hash.
 *
 * @internal
 *
 * @template Entity of AbstractEntity
 */
final class KeyRead
{
    /**
     * @var array<string, SerializedKeyResult<Entity>> - by {@see SerializedKeyResult::$hash}
     */
    private array $keys = [];

    /**
     * @var array<string, list<Entity>> - the entities the item of each key is read into, by {@see SerializedKeyResult::$hash}
     */
    private array $targets = [];

    /**
     * @var array<string, EntityDefinition<Entity>> - by physical table, whose key an item is hashed by
     */
    private array $tables = [];

    /**
     * @param bool $consistentRead - `ConsistentRead` of the `GetItem`/`BatchGetItem`
     * @param iterable<array{SerializedKeyResult<Entity>, ?Entity}> $keys - each key, and the entity its item is read into; without one, it is read into a new entity
     */
    public function __construct(
        private readonly bool $consistentRead,
        iterable $keys,
    ) {
        foreach ($keys as [$key, $target]) {
            $this->keys[$key->hash] ??= $key;
            $this->targets[$key->hash] ??= [];
            $this->tables[$key->definition->getTable()] ??= $key->definition;

            if ($target !== null && !\in_array($target, $this->targets[$key->hash], true)) {
                $this->targets[$key->hash][] = $target;
            }
        }
    }

    /**
     * The `GetItem` input of a read of one key alone, `null` for any other.
     *
     * @return ?array{TableName: string, Key: array<string, AttributeValue>, ConsistentRead: bool}
     */
    public function getItemRequest(): ?array
    {
        if (\count($this->keys) !== 1) {
            return null;
        }

        $key = array_values($this->keys)[0];

        return ['TableName' => $key->definition->getTable(), 'Key' => $key->fields, 'ConsistentRead' => $this->consistentRead];
    }

    /**
     * Splits the read into reads of at most `$size` keys, the most one `BatchGetItem` request takes across all of
     * its tables.
     *
     * @param positive-int $size
     *
     * @return list<self<Entity>>
     */
    public function chunks(int $size): array
    {
        $chunks = [];
        foreach (array_chunk($this->keys, $size, true) as $keys) {
            $chunk = clone $this;
            $chunk->keys = $keys;
            $chunk->targets = array_intersect_key($this->targets, $keys);

            $chunks[] = $chunk;
        }

        return $chunks;
    }

    /**
     * @return array<string, array{Keys: list<array<string, AttributeValue>>, ConsistentRead: bool}> - by physical table, as `BatchGetItem` takes them
     */
    public function requestItems(): array
    {
        $requestItems = [];
        foreach ($this->keys as $key) {
            $table = $key->definition->getTable();
            $requestItems[$table] ??= ['Keys' => [], 'ConsistentRead' => $this->consistentRead];
            $requestItems[$table]['Keys'][] = $key->fields;
        }

        return $requestItems;
    }

    /**
     * The key of the read an item answers. `null` for an item of a table the read does not name, and for an empty
     * one, which is what `GetItem` answers for a key without an item.
     *
     * @param string $table - the physical table, as `BatchGetItem` answers under it
     * @param array<string, AttributeValue> $item
     *
     * @return ?SerializedKeyResult<Entity>
     */
    public function keyOf(string $table, array $item): ?SerializedKeyResult
    {
        $definition = $this->tables[$table] ?? null;
        if ($definition === null || $item === []) {
            return null;
        }

        return $this->keys[SerializedKeyResult::fromItem($definition, $item)->hash] ?? null;
    }

    /**
     * @param SerializedKeyResult<Entity> $key
     *
     * @return list<Entity> - the entities the item of `$key` is read into; where there are none, it is read into a new one
     */
    public function targets(SerializedKeyResult $key): array
    {
        return $this->targets[$key->hash] ?? [];
    }
}
