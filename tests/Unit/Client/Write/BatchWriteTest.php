<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Client\Write;

use Shopware\DynamodbDalBundle\Client\Write\BatchWrite;
use Shopware\DynamodbDalBundle\Client\Key;
use Shopware\DynamodbDalBundle\Client\Write\WriteBack;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Exception\DuplicateKeyException;
use Shopware\DynamodbDalBundle\Serializer\NormalizerOperation;
use Shopware\DynamodbDalBundle\Serializer\Serializer;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\NormalEntity;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\OtherEntity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BatchWrite::class)]
class BatchWriteTest extends TestCase
{
    private Serializer $serializer;

    /**
     * @var EntityDefinition<NormalEntity>
     */
    private EntityDefinition $normal;

    /**
     * @var EntityDefinition<OtherEntity>
     */
    private EntityDefinition $other;

    protected function setUp(): void
    {
        $this->serializer = new Serializer();
        $this->normal = NormalEntity::createDefinition();
        $this->other = OtherEntity::createDefinition();
    }

    public function testAPutOfAKeyAlreadyPutIsRefused(): void
    {
        $batch = new BatchWrite();
        $this->put($batch, $this->entity('a'));

        $this->expectException(DuplicateKeyException::class);

        $this->put($batch, $this->entity('a'));
    }

    public function testADeleteOfAKeyAlreadyPutIsRefused(): void
    {
        $batch = new BatchWrite();
        $this->put($batch, $this->entity('a'));

        $this->expectException(DuplicateKeyException::class);

        $this->delete($batch, new Key(NormalEntity::class, 'a'));
    }

    /**
     * The key is the table's, so the same key value on another table names another item.
     */
    public function testTheSameKeyValueOnAnotherTableIsAnotherKey(): void
    {
        $batch = new BatchWrite();
        $this->put($batch, $this->entity('a'));
        $this->delete($batch, new Key(OtherEntity::class, 'a'));

        static::assertSame(['normal', 'other-physical'], array_keys($batch->requestItems()));
    }

    /**
     * The limit of a request counts the requests of every table in it, so a chunk is cut across tables, in the
     * order the requests were added.
     */
    public function testChunksCutTheRequestsAcrossTablesInTheOrderTheyWereAdded(): void
    {
        $batch = new BatchWrite();
        $this->put($batch, $this->entity('a'));
        $this->delete($batch, new Key(OtherEntity::class, 'o'));
        $this->put($batch, $this->entity('b'));

        $chunks = $batch->chunks(2);

        static::assertCount(2, $chunks);
        static::assertSame(['normal' => 1, 'other-physical' => 1], array_map(\count(...), $chunks[0]->requestItems()));
        static::assertSame(['normal' => 1], array_map(\count(...), $chunks[1]->requestItems()));
    }

    /**
     * A chunk hands back only the write-backs of its own puts.
     */
    public function testAChunkSettlesOnlyItsOwnPuts(): void
    {
        $a = $this->entity('a');
        $batch = new BatchWrite();
        $this->put($batch, $a);
        $this->put($batch, $this->entity('b'));

        static::assertSame([$a], $this->entities($batch->chunks(1)[0]->settle([])));
    }

    /**
     * What DynamoDB leaves unprocessed is not stored yet, so its entity stays pending until a later request stores it.
     */
    public function testSettleHandsBackThePutsThatWereNotLeftUnprocessed(): void
    {
        $a = $this->entity('a');
        $b = $this->entity('b');
        $batch = new BatchWrite();
        $this->put($batch, $a);
        $this->put($batch, $b);

        $requests = $batch->requestItems()['normal'] ?? [];
        static::assertCount(2, $requests);

        static::assertSame([$a], $this->entities($batch->settle(['normal' => [$requests[1]]])));
        static::assertSame([$b], $this->entities($batch->settle([])));
        static::assertSame([], $batch->settle([]));
    }

    public function testAStoredPutTakesBackTheFieldsItWroteAsAPut(): void
    {
        $batch = new BatchWrite();
        $this->put($batch, $this->entity('a'));

        [$writeBack] = $batch->settle([]);

        static::assertSame(NormalizerOperation::Put, $writeBack->operation);
        static::assertSame('a', $writeBack->fields['autofilledId'] ?? null);
    }

    /**
     * A delete brings no entity up to date, so one left unprocessed keeps no put pending.
     */
    public function testADeleteLeftUnprocessedKeepsNoPutPending(): void
    {
        $a = $this->entity('a');
        $batch = new BatchWrite();
        $this->put($batch, $a);
        $this->delete($batch, new Key(OtherEntity::class, 'o'));

        $unprocessed = ['other-physical' => $batch->requestItems()['other-physical'] ?? []];

        static::assertSame([$a], $this->entities($batch->settle($unprocessed)));
    }

    private function put(BatchWrite $batch, NormalEntity $entity): void
    {
        $batch->put($this->normal, $entity, $this->serializer->serialize($this->normal, $entity, NormalizerOperation::Put));
    }

    /**
     * @param Key<NormalEntity>|Key<OtherEntity> $key
     */
    private function delete(BatchWrite $batch, Key $key): void
    {
        $definition = $key->class === OtherEntity::class ? $this->other : $this->normal;

        $batch->delete($key, $this->serializer->serializeKey($definition, $key));
    }

    /**
     * @param list<WriteBack> $writeBacks
     *
     * @return list<object>
     */
    private function entities(array $writeBacks): array
    {
        return array_map(static fn (WriteBack $writeBack): object => $writeBack->entity, $writeBacks);
    }

    private function entity(string $id): NormalEntity
    {
        return new NormalEntity()->setAutofilledId($id)->setRequired('req');
    }
}
