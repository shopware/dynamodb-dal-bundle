<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Client;

use Shopware\DynamodbDalBundle\Client\Client;
use Shopware\DynamodbDalBundle\Client\Cursor;
use Shopware\DynamodbDalBundle\Client\Index;
use Shopware\DynamodbDalBundle\Client\Input\DeleteInput;
use Shopware\DynamodbDalBundle\Client\Input\GetInput;
use Shopware\DynamodbDalBundle\Client\Input\PutInput;
use Shopware\DynamodbDalBundle\Client\Input\QueryInput;
use Shopware\DynamodbDalBundle\Client\Input\RefreshInput;
use Shopware\DynamodbDalBundle\Client\Input\ScanInput;
use Shopware\DynamodbDalBundle\Client\Input\UpdateInput;
use Shopware\DynamodbDalBundle\Client\ReaderClient;
use Shopware\DynamodbDalBundle\Client\WriterClient;
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

    public function testReadStreamsWhateverTheReaderSearchYields(): void
    {
        $a = new NormalEntity()->setAutofilledId('a')->setRequired('req');
        $b = new NormalEntity()->setAutofilledId('b')->setRequired('req');

        $query = new ScanInput();

        // search() delegates the actual DynamoDB request to the reader; the SearchOutput merely wraps the
        // reader's entity stream. Reads no longer touch the DynamoDbClient directly.
        $this->reader->expects(static::once())
            ->method('search')
            ->with(NormalEntity::class, $query)
            ->willReturnCallback(static function () use ($a, $b): \Generator {
                yield ['autofilledId' => new AttributeValue(['S' => 'a'])] => $a;
                yield ['autofilledId' => new AttributeValue(['S' => 'b'])] => $b;
            });

        $result = $this->client->search(NormalEntity::class, $query);

        static::assertSame([$a, $b], $result->toArray());
    }

    public function testPageBuildsTheNextTokenFromTheRawKeyTheReaderYields(): void
    {
        $a = new NormalEntity()->setAutofilledId('a')->setRequired('req');
        $b = new NormalEntity()->setAutofilledId('b')->setRequired('req');

        $query = new ScanInput(limit: 1);

        $this->reader->expects(static::once())->method('search')
            ->with(NormalEntity::class, $query)
            ->willReturnCallback(static function () use ($a, $b): \Generator {
                yield ['autofilledId' => new AttributeValue(['S' => 'a'])] => $a;
                yield ['autofilledId' => new AttributeValue(['S' => 'b'])] => $b;
            });

        $page = $this->client->search(NormalEntity::class, $query)->page();

        static::assertSame([$a], $page->items);
        static::assertNotNull($page->next);
        static::assertEquals(['autofilledId' => new AttributeValue(['S' => 'a'])], Cursor::decode($page->next)->key);
    }

    public function testGetStreamsWhateverTheReaderGetYields(): void
    {
        $entity = new NormalEntity()->setAutofilledId('a')->setRequired('req');
        $get = new GetInput([NormalEntity::class => [new Index('a')]]);

        $this->reader->expects(static::once())
            ->method('get')
            ->with($get)
            ->willReturnCallback(static function () use ($entity): \Generator {
                yield $entity;
            });

        $result = $this->client->get($get);

        static::assertSame([$entity], $result->toArray());
        static::assertSame($entity, $result->first());
    }

    public function testGetWithNoMatchYieldsAnEmptyOutput(): void
    {
        $get = new GetInput([NormalEntity::class => [new Index('missing')]]);

        $this->reader->expects(static::once())
            ->method('get')
            ->with($get)
            ->willReturnCallback(static function (): \Generator {
                yield from [];
            });

        $result = $this->client->get($get);

        static::assertSame([], $result->toArray());
        static::assertNull($result->first());
    }

    public function testGetSpanningEntityClassesWrapsTheReaderStreamIntoABucketedOutput(): void
    {
        $normal = new NormalEntity()->setAutofilledId('a')->setRequired('req');
        $other = new OtherEntity()->setOtherId('o');
        $get = new GetInput([])
            ->withKey(NormalEntity::class, new Index('a'))
            ->withKey(OtherEntity::class, new Index('o'));

        $this->reader->expects(static::once())
            ->method('get')
            ->with($get)
            ->willReturnCallback(static function () use ($normal, $other): \Generator {
                yield $normal;
                yield $other;
            });

        $result = $this->client->get($get);

        static::assertSame([$normal, $other], $result->toArray());
        static::assertSame([$normal], $result->forEntity(NormalEntity::class));
        static::assertSame([
            NormalEntity::class => [$normal],
            OtherEntity::class => [$other],
        ], $result->grouped());
    }

    public function testRefreshDelegatesToTheReader(): void
    {
        $refresh = new RefreshInput([new NormalEntity()->setAutofilledId('a')->setRequired('req')], consistentRead: true);

        $this->reader->expects(static::once())
            ->method('refresh')
            ->with($refresh);

        $this->client->refresh($refresh);
    }

    public function testCountDelegatesToTheReader(): void
    {
        $query = new ScanInput();

        $this->reader->expects(static::once())
            ->method('count')
            ->with(NormalEntity::class, $query)
            ->willReturn(7);

        static::assertSame(7, $this->client->count(NormalEntity::class, $query));
    }

    public function testPutDelegatesToTheWriter(): void
    {
        $put = new PutInput(new NormalEntity()->setAutofilledId('a')->setRequired('req'));

        $this->writer->expects(static::once())
            ->method('put')
            ->with(NormalEntity::class, $put);

        $this->client->put(NormalEntity::class, $put);
    }

    public function testUpdateDelegatesToTheWriter(): void
    {
        $update = new UpdateInput(new Index('a'), ['required' => 'req']);

        $this->writer->expects(static::once())
            ->method('update')
            ->with(NormalEntity::class, $update);

        $this->client->update(NormalEntity::class, $update);
    }

    public function testDeleteDelegatesToTheWriter(): void
    {
        $delete = DeleteInput::fromIndex('a');

        $this->writer->expects(static::once())
            ->method('delete')
            ->with(NormalEntity::class, $delete);

        $this->client->delete(NormalEntity::class, $delete);
    }
}
