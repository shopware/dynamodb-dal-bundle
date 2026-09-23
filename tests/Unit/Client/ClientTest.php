<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Client;

use Shopware\DynamodbDalBundle\Client\Client;
use Shopware\DynamodbDalBundle\Client\Index;
use Shopware\DynamodbDalBundle\Client\Input\GetInput;
use Shopware\DynamodbDalBundle\Client\Input\QueryInput;
use Shopware\DynamodbDalBundle\Client\Input\RefreshInput;
use Shopware\DynamodbDalBundle\Client\Input\ScanInput;
use Shopware\DynamodbDalBundle\Client\ReaderClient;
use Shopware\DynamodbDalBundle\Client\WriterClient;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\NormalEntity;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\OtherEntity;
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

    private EntityDefinition $definition;

    private Client $client;

    protected function setUp(): void
    {
        $this->reader = $this->createMock(ReaderClient::class);
        $this->writer = $this->createMock(WriterClient::class);
        $this->definition = NormalEntity::createDefinition();

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
            ->with($this->definition, $query)
            ->willReturnCallback(static function () use ($a, $b): \Generator {
                yield $a;
                yield $b;
            });

        $result = $this->client->search($this->definition, $query);

        static::assertSame([$a, $b], $result->toArray());
    }

    public function testReadBuildsAQueryPageCursorViaCursorForFromTheReaderStream(): void
    {
        $a = new NormalEntity()->setAutofilledId('a')->setRequired('req');
        $b = new NormalEntity()->setAutofilledId('b')->setRequired('req');

        $query = new ScanInput(limit: 1);

        $this->reader->expects(static::once())->method('search')
            ->with($this->definition, $query)
            ->willReturnCallback(static function () use ($a, $b): \Generator {
                yield $a;
                yield $b;
            });

        $page = $this->client->search($this->definition, $query)->page();

        static::assertSame([$a], $page->items);
        static::assertNotNull($page->nextCursor);
        static::assertSame('normal', $page->nextCursor->table);
        static::assertSame('a', $page->nextCursor->primaryKey->hashValue);
        static::assertNull($page->nextCursor->indexKey);
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
            ->with($this->definition, $query)
            ->willReturn(7);

        static::assertSame(7, $this->client->count($this->definition, $query));
    }
}
