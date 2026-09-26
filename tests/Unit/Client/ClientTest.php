<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Client;

use Shopware\DynamodbDalBundle\Client\Client;
use Shopware\DynamodbDalBundle\Client\Cursor;
use Shopware\DynamodbDalBundle\Client\Input\BatchWriteInput;
use Shopware\DynamodbDalBundle\Client\Input\DeleteInput;
use Shopware\DynamodbDalBundle\Client\Input\GetInput;
use Shopware\DynamodbDalBundle\Client\Input\PutInput;
use Shopware\DynamodbDalBundle\Client\Input\QueryInput;
use Shopware\DynamodbDalBundle\Client\Input\RefreshInput;
use Shopware\DynamodbDalBundle\Client\Input\ScanInput;
use Shopware\DynamodbDalBundle\Client\Input\TransactWriteInput;
use Shopware\DynamodbDalBundle\Client\Input\UpdateInput;
use Shopware\DynamodbDalBundle\Client\Key;
use Shopware\DynamodbDalBundle\Client\ReaderClient;
use Shopware\DynamodbDalBundle\Client\WriterClient;
use Shopware\DynamodbDalBundle\Expression\Filter;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\NormalEntity;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\OtherEntity;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
#[CoversClass(Client::class)]
#[CoversClass(ScanInput::class)]
#[CoversClass(QueryInput::class)]
class ClientTest extends TestCase
{
    private ReaderClient&MockObject $reader;

    private WriterClient&MockObject $writer;

    private Client $client;

    protected function setUp(): void
    {
        $this->reader = $this->createMock(ReaderClient::class);
        $this->writer = $this->createMock(WriterClient::class);

        $this->client = new Client(
            $this->reader,
            $this->writer,
        );
    }

    public function testFindReadsTheOneKey(): void
    {
        $entity = $this->entity('a');
        $key = new Key(NormalEntity::class, 'a');

        $this->reader->expects(static::once())
            ->method('get')
            ->with(new GetInput([$key], consistentRead: true))
            ->willReturn(self::stream($entity));

        static::assertSame($entity, $this->client->find($key, consistentRead: true));
    }

    public function testFindWithoutAStoredItemIsNull(): void
    {
        $this->reader->expects(static::once())->method('get')->willReturn(self::stream());

        static::assertNull($this->client->find(new Key(NormalEntity::class, 'missing')));
    }

    public function testFindManyStreamsEveryKey(): void
    {
        $a = $this->entity('a');
        $o = new OtherEntity()->setOtherId('o');
        $keys = [new Key(NormalEntity::class, 'a'), new Key(OtherEntity::class, 'o'), new Key(NormalEntity::class, 'missing')];

        $this->reader->expects(static::once())
            ->method('get')
            ->with(new GetInput($keys))
            ->willReturn(self::stream($o, $a));

        static::assertSame([$o, $a], $this->client->findMany($keys)->toArray());
    }

    public function testGetStreamsWhateverTheReaderGetYields(): void
    {
        $entity = $this->entity('a');
        $get = new GetInput([new Key(NormalEntity::class, 'a')]);

        $this->reader->expects(static::once())
            ->method('get')
            ->with($get)
            ->willReturn(self::stream($entity));

        static::assertSame([$entity], $this->client->get($get)->toArray());
    }

    public function testGetWithNoMatchYieldsAnEmptyOutput(): void
    {
        $get = new GetInput([new Key(NormalEntity::class, 'missing')]);

        $this->reader->expects(static::once())
            ->method('get')
            ->with($get)
            ->willReturn(self::stream());

        static::assertNull($this->client->get($get)->first());
    }

    public function testGetSpanningEntityClassesBucketsTheEntitiesByClass(): void
    {
        $normal = $this->entity('a');
        $other = new OtherEntity()->setOtherId('o');
        $get = new GetInput([new Key(NormalEntity::class, 'a'), new Key(OtherEntity::class, 'o')]);

        $this->reader->expects(static::once())
            ->method('get')
            ->with($get)
            ->willReturn(self::stream($normal, $other));

        static::assertSame([
            NormalEntity::class => [$normal],
            OtherEntity::class => [$other],
        ], $this->client->get($get)->grouped());
    }

    public function testRefreshDelegatesToTheReader(): void
    {
        $refresh = new RefreshInput([$this->entity('a')], consistentRead: true);

        $this->reader->expects(static::once())
            ->method('refresh')
            ->with($refresh);

        $this->client->refresh($refresh);
    }

    public function testSearchStreamsWhateverTheReaderSearchYields(): void
    {
        $a = $this->entity('a');
        $b = $this->entity('b');
        $query = new ScanInput(NormalEntity::class);

        $this->reader->expects(static::once())
            ->method('search')
            ->with($query)
            ->willReturnCallback(static function () use ($a, $b): \Generator {
                yield ['autofilledId' => new AttributeValue(['S' => 'a'])] => $a;
                yield ['autofilledId' => new AttributeValue(['S' => 'b'])] => $b;
            });

        static::assertSame([$a, $b], $this->client->search($query)->toArray());
    }

    public function testPageBuildsTheNextTokenFromTheRawKeyTheReaderYields(): void
    {
        $a = $this->entity('a');
        $b = $this->entity('b');
        $query = new ScanInput(NormalEntity::class, limit: 1);

        $this->reader->expects(static::once())
            ->method('search')
            ->with($query)
            ->willReturnCallback(static function () use ($a, $b): \Generator {
                yield ['autofilledId' => new AttributeValue(['S' => 'a'])] => $a;
                yield ['autofilledId' => new AttributeValue(['S' => 'b'])] => $b;
            });

        $page = $this->client->search($query)->page();

        static::assertSame([$a], $page->items);
        static::assertNotNull($page->next);
        static::assertEquals(['autofilledId' => new AttributeValue(['S' => 'a'])], Cursor::decode($page->next)->key);
    }

    public function testCountDelegatesToTheReader(): void
    {
        $query = new QueryInput(NormalEntity::class, Filter::equals('autofilledId', 'a'));

        $this->reader->expects(static::once())
            ->method('count')
            ->with($query)
            ->willReturn(7);

        static::assertSame(7, $this->client->count($query));
    }

    public function testPutDelegatesToTheWriter(): void
    {
        $put = new PutInput($this->entity('a'));

        $this->writer->expects(static::once())->method('put')->with($put);

        $this->client->put($put);
    }

    public function testUpdateDelegatesToTheWriter(): void
    {
        $update = new UpdateInput(new Key(NormalEntity::class, 'a'), ['required' => 'req']);

        $this->writer->expects(static::once())->method('update')->with($update);

        $this->client->update($update);
    }

    public function testDeleteDelegatesToTheWriter(): void
    {
        $delete = new DeleteInput(new Key(NormalEntity::class, 'a'));

        $this->writer->expects(static::once())->method('delete')->with($delete);

        $this->client->delete($delete);
    }

    public function testBatchWriteDelegatesToTheWriter(): void
    {
        $batch = new BatchWriteInput(deletes: [new Key(NormalEntity::class, 'a')]);

        $this->writer->expects(static::once())->method('batchWrite')->with($batch);

        $this->client->batchWrite($batch);
    }

    public function testTransactWriteDelegatesToTheWriter(): void
    {
        $transaction = new TransactWriteInput(new PutInput($this->entity('a')));

        $this->writer->expects(static::once())->method('transactWrite')->with($transaction);

        $this->client->transactWrite($transaction);
    }

    /**
     * Not `final`, so an application's test can double it, as it doubles any service it depends on.
     */
    public function testATestCanDoubleTheClient(): void
    {
        $client = static::createStub(Client::class);
        $client->method('find')->willReturn($this->entity('a'));

        static::assertInstanceOf(NormalEntity::class, $client->find(new Key(NormalEntity::class, 'a')));
    }

    private function entity(string $id): NormalEntity
    {
        return new NormalEntity()->setAutofilledId($id)->setRequired('req');
    }

    /**
     * @return \Generator<int, NormalEntity|OtherEntity>
     */
    private static function stream(NormalEntity|OtherEntity ...$entities): \Generator
    {
        yield from array_values($entities);
    }
}
