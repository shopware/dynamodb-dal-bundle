<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Client;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Client\Cursor\Cursor;
use Shopware\DynamodbDalBundle\Client\Index;
use Shopware\DynamodbDalBundle\Client\Input\GetInput;
use Shopware\DynamodbDalBundle\Client\Input\QueryInput;
use Shopware\DynamodbDalBundle\Client\Input\ScanInput;
use Shopware\DynamodbDalBundle\Client\ReaderClient;
use Shopware\DynamodbDalBundle\Expression\ExpressionCompiler;
use Shopware\DynamodbDalBundle\Expression\Filter;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\EntityDefinitionRegistry;
use Shopware\DynamodbDalBundle\Definition\FieldPath;
use Shopware\DynamodbDalBundle\Serializer\SerializedFieldResult;
use Shopware\DynamodbDalBundle\Serializer\SerializedResult;
use Shopware\DynamodbDalBundle\Serializer\Serializer;
use Shopware\DynamodbDalBundle\Tests\Unit\DynamoDbResultTestTrait;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\NormalEntity;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\OtherEntity;
use AsyncAws\DynamoDb\DynamoDbClient;
use AsyncAws\DynamoDb\Enum\Select;
use AsyncAws\DynamoDb\Input\QueryInput as DynamoDbQueryInput;
use AsyncAws\DynamoDb\Input\ScanInput as DynamoDbScanInput;
use AsyncAws\DynamoDb\Result\ScanOutput;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
#[CoversClass(ReaderClient::class)]
class ReaderClientTest extends TestCase
{
    use DynamoDbResultTestTrait;

    private const string TABLE = 'normal';

    private DynamoDbClient&MockObject $dynamo;

    private Serializer&MockObject $serializer;

    private EntityDefinition $definition;

    private ReaderClient $reader;

    protected function setUp(): void
    {
        $this->dynamo = $this->createMock(DynamoDbClient::class);
        $this->serializer = $this->createMock(Serializer::class);
        $this->definition = NormalEntity::createDefinition();

        $registry = $this->registry();

        $this->reader = new ReaderClient($this->dynamo, $this->serializer, new ExpressionCompiler(), $registry);
    }

    public function testSearchScanDeserializesEveryItem(): void
    {
        $a = new NormalEntity()->setAutofilledId('a')->setRequired('req');
        $b = new NormalEntity()->setAutofilledId('b')->setRequired('req');
        $itemA = $this->item('a');
        $itemB = $this->item('b');

        $output = self::scanOutput([$itemA, $itemB]);
        $this->dynamo->expects(static::once())->method('scan')->willReturn($output);
        $this->dynamo->expects(static::never())->method('query');

        $this->serializer->method('deserialize')->willReturnCallback(
            static fn (EntityDefinition $definition, array $item): NormalEntity => $item === $itemA ? $a : $b,
        );

        static::assertSame([$a, $b], iterator_to_array($this->reader->search($this->definition, new ScanInput()), false));
    }

    public function testSearchScanThreadsConsistentReadAndTableIntoTheScanInput(): void
    {
        $output = self::scanOutput();
        $this->dynamo->expects(static::once())
            ->method('scan')
            ->with(static::callback(static function (DynamoDbScanInput $input): bool {
                static::assertSame(self::TABLE, $input->getTableName());
                static::assertTrue($input->getConsistentRead());
                static::assertSame([], $input->getExclusiveStartKey());

                return true;
            }))
            ->willReturn($output);

        iterator_to_array($this->reader->search($this->definition, new ScanInput(consistentRead: true)), false);
    }

    public function testSearchScanDefaultsToEventuallyConsistentRead(): void
    {
        $output = self::scanOutput();
        $this->dynamo->expects(static::once())
            ->method('scan')
            ->with(static::callback(static function (DynamoDbScanInput $input): bool {
                static::assertNull($input->getConsistentRead());

                return true;
            }))
            ->willReturn($output);

        iterator_to_array($this->reader->search($this->definition, new ScanInput()), false);
    }

    public function testSearchQueryThreadsKeyConditionForwardIndexAndConsistentRead(): void
    {
        $a = new NormalEntity()->setAutofilledId('a')->setRequired('req');
        $itemA = $this->item('a');

        $output = self::queryOutput([$itemA]);
        $this->dynamo->expects(static::never())->method('scan');
        $this->dynamo->expects(static::once())
            ->method('query')
            ->with(static::callback(static function (DynamoDbQueryInput $input): bool {
                static::assertSame(self::TABLE, $input->getTableName());
                static::assertIsString($input->getKeyConditionExpression());
                static::assertIsString($input->getFilterExpression());
                static::assertTrue($input->getScanIndexForward());
                static::assertNull($input->getIndexName());
                static::assertTrue($input->getConsistentRead());
                static::assertContains('autofilledId', $input->getExpressionAttributeNames());
                static::assertContains('name', $input->getExpressionAttributeNames());

                return true;
            }))
            ->willReturn($output);

        $this->serializer->method('deserialize')->willReturn($a);

        $query = new QueryInput(
            Filter::equals('autofilledId', 'a'),
            filter: Filter::equals('name', 'foo'),
            consistentRead: true,
        );

        static::assertSame([$a], iterator_to_array($this->reader->search($this->definition, $query), false));
    }

    public function testSearchQueryThreadsReverseOrderIndexNameAndDefaultConsistentRead(): void
    {
        $output = self::queryOutput();
        $this->dynamo->expects(static::once())
            ->method('query')
            ->with(static::callback(static function (DynamoDbQueryInput $input): bool {
                static::assertSame('someIndex', $input->getIndexName());
                static::assertFalse($input->getScanIndexForward());
                static::assertNull($input->getConsistentRead());

                return true;
            }))
            ->willReturn($output);

        $query = new QueryInput(Filter::equals('name', 'x'), index: 'someIndex', forward: false);

        iterator_to_array($this->reader->search($this->definition, $query), false);
    }

    public function testSearchResumesFromTheIncomingCursorAsExclusiveStartKey(): void
    {
        $output = self::scanOutput();
        $this->dynamo->expects(static::once())
            ->method('scan')
            ->with(static::callback(static function (DynamoDbScanInput $input): bool {
                static::assertSame('cursor-id', ($input->getExclusiveStartKey()['autofilledId'] ?? null)?->getS());

                return true;
            }))
            ->willReturn($output);

        $cursor = new Cursor('normal', new Index('cursor-id'));

        // The reader turns the cursor into the ExclusiveStartKey via the serializer; mirror that here.
        $this->serializer->expects(static::once())
            ->method('serializeCursor')
            ->with($this->definition, $cursor)
            ->willReturn(['autofilledId' => new AttributeValue(['S' => 'cursor-id'])]);

        iterator_to_array($this->reader->search($this->definition, new ScanInput(cursor: $cursor)), false);
    }

    public function testCountSumsSelectCountAcrossPages(): void
    {
        $firstOutput = self::scanOutput(lastEvaluatedKey: ['autofilledId' => new AttributeValue(['S' => 'page-1'])], count: 5);
        $secondOutput = self::scanOutput(count: 3);

        $calls = 0;
        $this->dynamo->expects(static::exactly(2))
            ->method('scan')
            ->willReturnCallback(static function (DynamoDbScanInput $input) use (&$calls, $firstOutput, $secondOutput): ScanOutput {
                static::assertSame(Select::COUNT, $input->getSelect());

                return ++$calls === 1 ? $firstOutput : $secondOutput;
            });

        $this->serializer->expects(static::never())->method('deserialize');

        static::assertSame(8, $this->reader->count($this->definition, new ScanInput()));
    }

    public function testGetSingleKeyIssuesOneGetItemAndDeserializesTheFoundEntity(): void
    {
        $entity = new NormalEntity()->setAutofilledId('a')->setRequired('req');
        $item = $this->item('a');

        $this->stubKeySerialization();

        $output = self::getItemOutput($item);

        $this->dynamo->expects(static::once())
            ->method('getItem')
            ->with(static::callback(static function (array $request): bool {
                static::assertSame(self::TABLE, $request['TableName']);
                static::assertEquals(['autofilledId' => new AttributeValue(['S' => 'a'])], $request['Key']);
                static::assertTrue($request['ConsistentRead']);

                return true;
            }))
            ->willReturn($output);
        $this->dynamo->expects(static::never())->method('batchGetItem');

        $this->serializer->expects(static::once())->method('deserialize')->with($this->definition, $item)->willReturn($entity);

        $result = iterator_to_array($this->reader->get(new GetInput([NormalEntity::class => [new Index('a')]], true)), false);

        static::assertSame([$entity], $result);
    }

    public function testGetSingleKeyYieldsNothingWhenTheItemIsAbsent(): void
    {
        $this->stubKeySerialization();

        $output = self::getItemOutput(); // GetItem returns [] when the key matches no item

        $this->dynamo->expects(static::once())->method('getItem')->willReturn($output);

        // deserialize([]) returns null for an absent item; the reader must not yield it.
        $this->serializer->expects(static::once())->method('deserialize')->with($this->definition, [])->willReturn(null);

        $result = iterator_to_array($this->reader->get(new GetInput([NormalEntity::class => [new Index('missing')]])), false);

        static::assertSame([], $result);
    }

    public function testGetMultipleKeysIssuesOneBatchGetItemAndYieldsEveryResponse(): void
    {
        $a = new NormalEntity()->setAutofilledId('a')->setRequired('req');
        $b = new NormalEntity()->setAutofilledId('b')->setRequired('req');
        $itemA = $this->item('a');
        $itemB = $this->item('b');

        $this->stubKeySerialization();

        $output = self::batchGetItemOutput([self::TABLE => [$itemA, $itemB]]);

        $this->dynamo->expects(static::once())->method('batchGetItem')->willReturn($output);
        $this->dynamo->expects(static::never())->method('getItem');

        $this->serializer->method('deserialize')->willReturnCallback(
            static fn (EntityDefinition $definition, array $item): NormalEntity => $item === $itemA ? $a : $b,
        );

        $result = iterator_to_array(
            $this->reader->get(new GetInput([NormalEntity::class => [new Index('a'), new Index('b')]])),
            false,
        );

        static::assertSame([$a, $b], $result);
    }

    public function testGetKeysSpanningTablesIssueOneBatchGetItemAcrossBothTables(): void
    {
        $normal = new NormalEntity()->setAutofilledId('a')->setRequired('req');
        $other = new OtherEntity()->setOtherId('o');
        $normalItem = $this->item('a');
        $otherItem = $this->item('o');

        // A second entity, registered alongside `normal`, with its own physical table name.
        $otherDefinition = OtherEntity::createDefinition();

        $reader = new ReaderClient(
            $this->dynamo,
            $this->serializer,
            new ExpressionCompiler(),
            new EntityDefinitionRegistry([
                $this->definition->getName() => $this->definition,
                $otherDefinition->getName() => $otherDefinition,
            ]),
        );

        $this->stubKeySerialization();

        // Responses come back keyed by physical table name.
        $output = self::batchGetItemOutput([
            self::TABLE => [$normalItem],
            'other-physical' => [$otherItem],
        ]);

        // Exactly one BatchGetItem, carrying both tables in RequestItems — never split per table.
        $this->dynamo->expects(static::once())
            ->method('batchGetItem')
            ->with(static::callback(static function (array $args): bool {
                $requestItems = $args['RequestItems'] ?? [];
                static::assertArrayHasKey(self::TABLE, $requestItems);
                static::assertArrayHasKey('other-physical', $requestItems);

                return true;
            }))
            ->willReturn($output);
        $this->dynamo->expects(static::never())->method('getItem');

        $this->serializer->method('deserialize')->willReturnCallback(
            static fn (EntityDefinition $definition, array $item): AbstractEntity => $item === $normalItem ? $normal : $other,
        );

        $entities = iterator_to_array(
            $reader->get(new GetInput([])
                ->withKey(NormalEntity::class, new Index('a'))
                ->withKey(OtherEntity::class, new Index('o'))),
            false,
        );

        // Both tables' entities are yielded, each deserialized with the definition its keys were built from.
        static::assertSame([$normal, $other], $entities);
    }

    private function registry(): EntityDefinitionRegistry
    {
        return new EntityDefinitionRegistry([$this->definition->getName() => $this->definition]);
    }

    /**
     * Stubs the serializer's key serialization used by {@see ReaderClient} to turn an {@see Index} into
     * the DynamoDB key map for `GetItem`/`BatchGetItem` requests.
     */
    private function stubKeySerialization(): void
    {
        $this->serializer->method('serializeKey')->willReturnCallback(
            static fn (EntityDefinition $definition, Index $key): array => self::serializedKey($definition, $key)->getFields(),
        );
    }

    /**
     * @param EntityDefinition<AbstractEntity> $definition
     */
    private static function serializedKey(EntityDefinition $definition, Index $key): SerializedResult
    {
        $fields = $key->getFields($definition);

        $serialized = [];
        foreach ($fields as $name => $value) {
            $path = FieldPath::tryParse($definition, $name);
            static::assertNotNull($path);
            static::assertIsString($value);

            $serialized[$name] = new SerializedFieldResult($path, new AttributeValue(['S' => $value]));
        }

        return new SerializedResult($definition, $serialized, $fields);
    }

    /**
     * A raw async-aws item map, the shape `ReaderClient::search()` reads via `$output->getItems()`.
     *
     * @return array<string, AttributeValue>
     */
    private function item(string $id): array
    {
        return ['autofilledId' => new AttributeValue(['S' => $id])];
    }
}
