<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Client\Write;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Client\Key;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Exception\DuplicateKeyException;
use Shopware\DynamodbDalBundle\Serializer\SerializedKeyResult;
use Shopware\DynamodbDalBundle\Serializer\SerializedResult;
use AsyncAws\DynamoDb\ValueObject\WriteRequest;

/**
 * The puts and deletes of a `BatchWriteItem` call, each under the key it names, and how the entities of the puts
 * DynamoDB has not stored yet are brought up to date once it has.
 *
 * DynamoDB refuses a request that names a key twice after the requests before it were written, and lets the later one
 * win where they are sent apart, so a key named twice is refused before any of the batch is sent.
 *
 * @internal
 */
final class BatchWrite
{
    /**
     * @var array<string, array{string, WriteRequest}> - physical table and request, by {@see SerializedKeyResult::$hash}
     */
    private array $requests = [];

    /**
     * @var array<string, WriteBack> - of the puts not stored yet, by {@see SerializedKeyResult::$hash}
     */
    private array $pending = [];

    /**
     * @throws DuplicateKeyException
     */
    public function put(EntityDefinition $definition, AbstractEntity $entity, SerializedResult $result): void
    {
        $key = SerializedKeyResult::fromItem($definition, $result->getFields());
        $this->add($entity, $key, new WriteRequest(['PutRequest' => $result->getPutExpression()]));

        $this->pending[$key->hash] = WriteBack::fields($entity, $definition, $result->getNormalizedFields(), $result->getOperation());
    }

    /**
     * @param AbstractEntity|Key<AbstractEntity> $key
     * @param SerializedKeyResult<AbstractEntity> $serializedKey
     *
     * @throws DuplicateKeyException
     */
    public function delete(AbstractEntity|Key $key, SerializedKeyResult $serializedKey): void
    {
        $this->add($key, $serializedKey, new WriteRequest(['DeleteRequest' => ['Key' => $serializedKey->fields]]));
    }

    /**
     * Splits the batch into batches of at most `$size` requests, the most one `BatchWriteItem` request takes across
     * all of its tables.
     *
     * @param positive-int $size
     *
     * @return list<self>
     */
    public function chunks(int $size): array
    {
        $chunks = [];
        foreach (array_chunk($this->requests, $size, true) as $requests) {
            $chunk = new self();
            $chunk->requests = $requests;
            $chunk->pending = array_intersect_key($this->pending, $requests);

            $chunks[] = $chunk;
        }

        return $chunks;
    }

    /**
     * @return array<string, list<WriteRequest>> - by physical table, as `BatchWriteItem` takes them
     */
    public function requestItems(): array
    {
        $requestItems = [];
        foreach ($this->requests as [$table, $request]) {
            $requestItems[$table][] = $request;
        }

        return $requestItems;
    }

    /**
     * Takes the puts DynamoDB stored with the last request off the pending ones: all but those it left unprocessed.
     *
     * @param array<string, WriteRequest[]> $unprocessed - by physical table, as `BatchWriteItem` returns them
     *
     * @return list<WriteBack> - of the puts stored
     */
    public function settle(array $unprocessed): array
    {
        // A left-over put is pending, so a pending put of its table knows the key the table shares
        $definitions = [];
        foreach ($this->pending as $writeBack) {
            $definitions[$writeBack->definition->getTable()] = $writeBack->definition;
        }

        $leftOver = [];
        foreach ($unprocessed as $table => $requests) {
            foreach ($requests as $request) {
                $item = $request->getPutRequest()?->getItem();
                if ($item !== null && isset($definitions[$table])) {
                    $leftOver[SerializedKeyResult::fromItem($definitions[$table], $item)->hash] = true;
                }
            }
        }

        $stored = array_diff_key($this->pending, $leftOver);
        $this->pending = array_intersect_key($this->pending, $leftOver);

        return array_values($stored);
    }

    /**
     * @param AbstractEntity|Key<AbstractEntity> $namedBy - the entity or key that names `$key`, for the exception
     * @param SerializedKeyResult<AbstractEntity> $key
     *
     * @throws DuplicateKeyException
     */
    private function add(AbstractEntity|Key $namedBy, SerializedKeyResult $key, WriteRequest $request): void
    {
        if (isset($this->requests[$key->hash])) {
            throw new DuplicateKeyException($key->definition, $namedBy);
        }

        $this->requests[$key->hash] = [$key->definition->getTable(), $request];
    }
}
