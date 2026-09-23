<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Client;

use Shopware\DynamodbDalBundle\Client\Index;
use Shopware\DynamodbDalBundle\Client\Input\DeleteInput;
use Shopware\DynamodbDalBundle\Client\Input\PutInput;
use Shopware\DynamodbDalBundle\Client\WriterClient;
use Shopware\DynamodbDalBundle\Criteria\ExpressionCompiler;
use Shopware\DynamodbDalBundle\Criteria\Filter;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\EntityDefinitionRegistry;
use Shopware\DynamodbDalBundle\Definition\FieldPath;
use Shopware\DynamodbDalBundle\Serializer\SerializedFieldResult;
use Shopware\DynamodbDalBundle\Serializer\SerializedResult;
use Shopware\DynamodbDalBundle\Serializer\Serializer;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\NormalEntity;
use AsyncAws\Core\Test\ResultMockFactory;
use AsyncAws\DynamoDb\DynamoDbClient;
use AsyncAws\DynamoDb\Exception\TransactionCanceledException;
use AsyncAws\DynamoDb\Result\BatchWriteItemOutput;
use AsyncAws\DynamoDb\Result\TransactWriteItemsOutput;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;
use AsyncAws\DynamoDb\ValueObject\TransactWriteItem;
use AsyncAws\DynamoDb\ValueObject\WriteRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Unit-level coverage for the branches of {@see WriterClient} that the integration test cannot reach with a
 * real DynamoDB: the zero-input no-ops, the condition-expression fallback to a transaction, and the
 * batch-write retry loop (which only runs when the service returns UnprocessedItems). The DynamoDB client is
 * mocked; write results are produced with {@see ResultMockFactory} so their final `resolve()` works. A real
 * {@see NormalEntity} definition and {@see ExpressionCompiler} are used so condition filters compile for real.
 */
#[AllowMockObjectsWithoutExpectations]
#[CoversClass(WriterClient::class)]
class WriterClientTest extends TestCase
{
    private const string SERIALIZED_ID = 'x';

    private DynamoDbClient&MockObject $client;

    private Serializer&MockObject $serializer;

    /**
     * @var EntityDefinition<NormalEntity>
     */
    private EntityDefinition $definition;

    private WriterClient $writer;

    protected function setUp(): void
    {
        $this->client = $this->createMock(DynamoDbClient::class);
        $this->serializer = $this->createMock(Serializer::class);
        $this->definition = NormalEntity::createDefinition();

        $this->serializer->method('serialize')->willReturn($this->serializedResult());
        $this->serializer->method('serializeKey')
            ->willReturn(['autofilledId' => new AttributeValue(['S' => self::SERIALIZED_ID])]);

        $registry = new EntityDefinitionRegistry([$this->definition->getName() => $this->definition]);
        $this->writer = new WriterClient($this->client, $this->serializer, new ExpressionCompiler(), $registry);
    }

    public function testPutWithoutInputsIsANoOp(): void
    {
        $this->client->expects(static::never())->method('putItem');
        $this->client->expects(static::never())->method('batchWriteItem');
        $this->client->expects(static::never())->method('transactWriteItems');

        $this->writer->put($this->definition);
    }

    public function testUpdateWithoutInputsIsANoOp(): void
    {
        $this->client->expects(static::never())->method('updateItem');
        $this->client->expects(static::never())->method('transactWriteItems');

        $this->writer->update($this->definition);
    }

    public function testDeleteWithoutInputsIsANoOp(): void
    {
        $this->client->expects(static::never())->method('deleteItem');
        $this->client->expects(static::never())->method('batchWriteItem');
        $this->client->expects(static::never())->method('transactWriteItems');

        $this->writer->delete($this->definition);
    }

    public function testPutWithAConditionOnSeveralInputsFallsBackToTransactWriteItems(): void
    {
        $this->client->expects(static::never())->method('batchWriteItem');
        $this->client->expects(static::once())
            ->method('transactWriteItems')
            ->with(static::callback(static function (array $args): bool {
                $items = $args['TransactItems'] ?? [];

                return \count($items) === 2
                    && $items[0] instanceof TransactWriteItem
                    && $items[1] instanceof TransactWriteItem;
            }))
            ->willReturn(ResultMockFactory::create(TransactWriteItemsOutput::class));

        $entityA = $this->entity('a');
        $entityB = $this->entity('b');

        $this->writer->put(
            $this->definition,
            new PutInput($entityA, Filter::notExists('autofilledId')),
            new PutInput($entityB),
        );

        static::assertSame(self::SERIALIZED_ID, $entityA->getAutofilledId());
        static::assertSame(self::SERIALIZED_ID, $entityB->getAutofilledId());
    }

    public function testDeleteWithAConditionOnSeveralInputsFallsBackToTransactWriteItems(): void
    {
        $this->client->expects(static::never())->method('batchWriteItem');
        $this->client->expects(static::once())
            ->method('transactWriteItems')
            ->with(static::callback(static fn (array $args): bool => \count($args['TransactItems'] ?? []) === 2))
            ->willReturn(ResultMockFactory::create(TransactWriteItemsOutput::class));

        $this->writer->delete(
            $this->definition,
            new DeleteInput(new Index('a'), Filter::exists('autofilledId')),
            new DeleteInput(new Index('b')),
        );
    }

    public function testTransactWriteRetriesOnTransactionConflictAndSucceeds(): void
    {
        $conflict = $this->transactionCanceledException('TransactionConflict');
        $success = ResultMockFactory::create(TransactWriteItemsOutput::class);

        $attempts = 0;
        $this->client->expects(static::exactly(2))
            ->method('transactWriteItems')
            ->willReturnCallback(static function () use (&$attempts, $conflict, $success): TransactWriteItemsOutput {
                ++$attempts;

                if ($attempts === 1) {
                    throw $conflict;
                }

                return $success;
            });

        $this->writer->delete(
            $this->definition,
            new DeleteInput(new Index('a'), Filter::exists('autofilledId')),
            new DeleteInput(new Index('b')),
        );
    }

    public function testTransactWriteRethrowsAfterExhaustingConflictRetries(): void
    {
        $this->client->expects(static::exactly(3))
            ->method('transactWriteItems')
            ->willThrowException($this->transactionCanceledException('TransactionConflict'));

        $this->expectException(TransactionCanceledException::class);

        $this->writer->delete(
            $this->definition,
            new DeleteInput(new Index('a'), Filter::exists('autofilledId')),
            new DeleteInput(new Index('b')),
        );
    }

    public function testTransactWriteDoesNotRetryANonConflictCancellationReason(): void
    {
        $this->client->expects(static::once())
            ->method('transactWriteItems')
            ->willThrowException($this->transactionCanceledException('ConditionalCheckFailed'));

        $this->expectException(TransactionCanceledException::class);

        $this->writer->delete(
            $this->definition,
            new DeleteInput(new Index('a'), Filter::exists('autofilledId')),
            new DeleteInput(new Index('b')),
        );
    }

    public function testTransactWriteDoesNotRetryWhenAConflictIsMixedWithAnotherFailureReason(): void
    {
        $this->client->expects(static::once())
            ->method('transactWriteItems')
            ->willThrowException($this->transactionCanceledException('TransactionConflict', 'ConditionalCheckFailed'));

        $this->expectException(TransactionCanceledException::class);

        $this->writer->delete(
            $this->definition,
            new DeleteInput(new Index('a'), Filter::exists('autofilledId')),
            new DeleteInput(new Index('b')),
        );
    }

    public function testBatchWriteResubmitsUnprocessedItemsUntilNoneRemain(): void
    {
        $table = $this->definition->getTable();
        $unprocessed = [$table => [
            new WriteRequest(['PutRequest' => ['Item' => ['autofilledId' => new AttributeValue(['S' => 'b'])]]]),
        ]];

        $withUnprocessed = ResultMockFactory::create(BatchWriteItemOutput::class, ['unprocessedItems' => $unprocessed]);
        $done = ResultMockFactory::create(BatchWriteItemOutput::class, ['unprocessedItems' => []]);

        $requestItemsPerCall = [];
        $this->client->expects(static::exactly(2))
            ->method('batchWriteItem')
            ->willReturnCallback(static function (array $args) use (&$requestItemsPerCall, $withUnprocessed, $done): BatchWriteItemOutput {
                $requestItemsPerCall[] = $args['RequestItems'] ?? null;

                return \count($requestItemsPerCall) === 1 ? $withUnprocessed : $done;
            });

        $entityA = $this->entity('a');
        $entityB = $this->entity('b');

        $this->writer->put($this->definition, new PutInput($entityA), new PutInput($entityB));

        // First call submits both items keyed by the table; the retry resubmits exactly the unprocessed map
        // (verbatim, not double-nested under the table name again).
        static::assertCount(2, $requestItemsPerCall[0][$table] ?? []);
        static::assertSame($unprocessed, $requestItemsPerCall[1]);

        static::assertSame(self::SERIALIZED_ID, $entityA->getAutofilledId());
        static::assertSame(self::SERIALIZED_ID, $entityB->getAutofilledId());
    }

    private function entity(string $id): NormalEntity
    {
        return new NormalEntity()->setAutofilledId($id)->setRequired('req');
    }

    private function serializedResult(): SerializedResult
    {
        $idPath = FieldPath::tryParse($this->definition, 'autofilledId');
        static::assertNotNull($idPath);

        return new SerializedResult(
            $this->definition,
            ['autofilledId' => new SerializedFieldResult($idPath, new AttributeValue(['S' => self::SERIALIZED_ID]))],
            ['autofilledId' => self::SERIALIZED_ID],
        );
    }

    private function transactionCanceledException(string ...$cancellationReasonCodes): TransactionCanceledException
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getInfo')->willReturnCallback(static fn (string $type): mixed => match ($type) {
            'http_code' => 400,
            'url' => 'https://dynamodb.local',
            default => null,
        });
        $response->expects(static::once())->method('toArray')->with(false)->willReturn([
            'CancellationReasons' => array_map(
                static fn (string $code): array => ['Code' => $code],
                $cancellationReasonCodes,
            ),
        ]);

        return new TransactionCanceledException($response);
    }
}
