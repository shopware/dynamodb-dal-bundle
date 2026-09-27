<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Client\Read;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Client\Key;
use Shopware\DynamodbDalBundle\Client\Read\KeyRead;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Serializer\SerializedKeyResult;
use Shopware\DynamodbDalBundle\Serializer\Serializer;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\NormalEntity;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\OtherEntity;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(KeyRead::class)]
class KeyReadTest extends TestCase
{
    private Serializer $serializer;

    /**
     * @var EntityDefinition<NormalEntity>
     */
    private EntityDefinition $normal;

    protected function setUp(): void
    {
        $this->serializer = new Serializer();
        $this->normal = NormalEntity::createDefinition();
    }

    public function testAReadOfOneKeyIsAGetItem(): void
    {
        $read = $this->read([[$this->key('a'), null]], consistentRead: true);

        static::assertEquals([
            'TableName' => 'normal',
            'Key' => ['autofilledId' => new AttributeValue(['S' => 'a'])],
            'ConsistentRead' => true,
        ], $read->getItemRequest());
    }

    public function testAReadOfSeveralKeysIsNoGetItem(): void
    {
        static::assertNull($this->read([[$this->key('a'), null], [$this->key('b'), null]])->getItemRequest());
    }

    /**
     * `BatchGetItem` refuses a request that names a key twice, so one given twice is a `GetItem` of it alone.
     */
    public function testAKeyGivenTwiceIsReadOnce(): void
    {
        static::assertNotNull($this->read([[$this->key('a'), null], [$this->key('a'), null]])->getItemRequest());
    }

    /**
     * The limit of a request counts the keys of every table in it, so a chunk is cut across tables.
     */
    public function testChunksCutTheKeysAcrossTablesInTheOrderTheyWereGiven(): void
    {
        $other = OtherEntity::createDefinition();
        $read = $this->read([
            [$this->key('a'), null],
            [$this->serializer->serializeKey($other, new Key(OtherEntity::class, 'o')), null],
            [$this->key('b'), null],
        ]);

        $chunks = $read->chunks(2);

        static::assertCount(2, $chunks);
        static::assertSame(['normal' => 1, 'other-physical' => 1], array_map(static fn (array $table): int => \count($table['Keys']), $chunks[0]->requestItems()));
        static::assertSame(['normal' => 1], array_map(static fn (array $table): int => \count($table['Keys']), $chunks[1]->requestItems()));
    }

    /**
     * A chunk answers for its own keys only, so an item finds its entities in the chunk that asked for it.
     */
    public function testAChunkKnowsOnlyItsOwnKeys(): void
    {
        $b = $this->entity('b');
        $chunks = $this->read([[$this->key('a'), $this->entity('a')], [$this->key('b'), $b]])->chunks(1);

        static::assertNull($chunks[0]->keyOf('normal', $this->item('b')));

        $key = $chunks[1]->keyOf('normal', $this->item('b'));
        static::assertNotNull($key);
        static::assertSame([$b], $chunks[1]->targets($key));
    }

    /**
     * `BatchGetItem` answers in no order, so an item finds the key it answers by its hash.
     */
    public function testAnItemAnswersTheKeyOfItsHash(): void
    {
        $read = $this->read([[$this->key('a'), null], [$this->key('b'), null]]);

        static::assertSame($this->key('b')->hash, $read->keyOf('normal', $this->item('b'))?->hash);
        static::assertSame($this->key('a')->hash, $read->keyOf('normal', $this->item('a'))?->hash);
    }

    public function testAKeyGivenForSeveralEntitiesIsReadIntoEachOfThemOnce(): void
    {
        $first = $this->entity('a');
        $second = $this->entity('a');
        $read = $this->read([[$this->key('a'), $first], [$this->key('a'), $second], [$this->key('a'), $first]]);

        static::assertSame([$first, $second], $read->targets($this->key('a')));
    }

    /**
     * An item of a key given without an entity is read into a new one.
     */
    public function testAKeyGivenWithoutAnEntityHasNoTargets(): void
    {
        static::assertSame([], $this->read([[$this->key('a'), null]])->targets($this->key('a')));
    }

    /**
     * `GetItem` answers a key without an item with an empty one.
     */
    public function testAnEmptyItemAnswersNoKey(): void
    {
        static::assertNull($this->read([[$this->key('a'), null]])->keyOf('normal', []));
    }

    public function testAnItemOfATableTheReadDoesNotNameAnswersNoKey(): void
    {
        static::assertNull($this->read([[$this->key('a'), null]])->keyOf('other-physical', $this->item('a')));
    }

    /**
     * @param list<array{SerializedKeyResult<AbstractEntity>, ?AbstractEntity}> $keys
     *
     * @return KeyRead<AbstractEntity>
     */
    private function read(array $keys, bool $consistentRead = false): KeyRead
    {
        return new KeyRead($consistentRead, $keys);
    }

    /**
     * @return SerializedKeyResult<NormalEntity>
     */
    private function key(string $id): SerializedKeyResult
    {
        return $this->serializer->serializeKey($this->normal, new Key(NormalEntity::class, $id));
    }

    /**
     * @return array<string, AttributeValue>
     */
    private function item(string $id): array
    {
        return [
            'autofilledId' => new AttributeValue(['S' => $id]),
            'required' => new AttributeValue(['S' => 'req']),
        ];
    }

    private function entity(string $id): NormalEntity
    {
        return new NormalEntity()->setAutofilledId($id)->setRequired('req');
    }
}
