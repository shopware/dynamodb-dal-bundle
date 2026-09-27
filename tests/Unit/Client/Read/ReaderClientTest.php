<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Client\Read;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Client\Cursor;
use Shopware\DynamodbDalBundle\Client\Key;
use Shopware\DynamodbDalBundle\Client\Input\GetInput;
use Shopware\DynamodbDalBundle\Client\Input\QueryInput;
use Shopware\DynamodbDalBundle\Client\Input\RefreshInput;
use Shopware\DynamodbDalBundle\Client\Input\ScanInput;
use Shopware\DynamodbDalBundle\Client\Read\ReaderClient;
use Shopware\DynamodbDalBundle\Client\Read\ReadRequestFactory;
use Shopware\DynamodbDalBundle\Expression\FilterCompiler;
use Shopware\DynamodbDalBundle\Expression\Filter;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\EntityDefinitionRegistry;
use Shopware\DynamodbDalBundle\Definition\FieldPath;
use Shopware\DynamodbDalBundle\Definition\IndexSchema;
use Shopware\DynamodbDalBundle\Exception\InvalidCursorException;
use Shopware\DynamodbDalBundle\Exception\InvalidKeyConditionException;
use Shopware\DynamodbDalBundle\Exception\UnknownEntityDefinitionException;
use Shopware\DynamodbDalBundle\Exception\UnknownIndexException;
use Shopware\DynamodbDalBundle\Serializer\NormalizerOperation;
use Shopware\DynamodbDalBundle\Serializer\SerializedFieldResult;
use Shopware\DynamodbDalBundle\Serializer\SerializedKeyResult;
use Shopware\DynamodbDalBundle\Serializer\SerializedResult;
use Shopware\DynamodbDalBundle\Serializer\Serializer;
use Shopware\DynamodbDalBundle\Tests\Unit\DynamoDbResultTestTrait;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\NormalEntity;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\OtherEntity;
use AsyncAws\DynamoDb\DynamoDbClient;
use AsyncAws\DynamoDb\Enum\Select;
use AsyncAws\DynamoDb\Input\QueryInput as DynamoDbQueryInput;
use AsyncAws\DynamoDb\Input\ScanInput as DynamoDbScanInput;
use AsyncAws\DynamoDb\Result\BatchGetItemOutput;
use AsyncAws\DynamoDb\Result\QueryOutput;
use AsyncAws\DynamoDb\Result\ScanOutput;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;
use AsyncAws\DynamoDb\ValueObject\KeysAndAttributes;
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

        $this->reader = $this->createReader($registry);
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

        static::assertSame([$a, $b], iterator_to_array($this->reader->search(new ScanInput(NormalEntity::class)), false));
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

        iterator_to_array($this->reader->search(new ScanInput(NormalEntity::class, consistentRead: true)), false);
    }

    public function testSearchScanDefaultsToEventuallyConsistentRead(): void
    {
        $output = self::scanOutput();
        $this->dynamo->expects(static::once())
            ->method('scan')
            ->with(static::callback(static function (DynamoDbScanInput $input): bool {
                static::assertFalse($input->getConsistentRead());

                return true;
            }))
            ->willReturn($output);

        iterator_to_array($this->reader->search(new ScanInput(NormalEntity::class)), false);
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
            NormalEntity::class,
            Filter::keyFilter(Filter::equals('autofilledId', 'a')),
            filter: Filter::equals('name', 'foo'),
            consistentRead: true,
        );

        static::assertSame([$a], iterator_to_array($this->reader->search($query), false));
    }

    public function testSearchQueryThreadsReverseOrderIndexNameAndDefaultConsistentRead(): void
    {
        $output = self::queryOutput();
        $this->dynamo->expects(static::once())
            ->method('query')
            ->with(static::callback(static function (DynamoDbQueryInput $input): bool {
                static::assertSame('someIndex', $input->getIndexName());
                static::assertFalse($input->getScanIndexForward());
                static::assertFalse($input->getConsistentRead());

                return true;
            }))
            ->willReturn($output);

        $query = new QueryInput(NormalEntity::class, Filter::keyFilter(Filter::equals('name', 'x')), index: 'someIndex', forward: false);

        iterator_to_array($this->indexedReader()->search($query), false);
    }

    public function testSearchKeysEachEntityByItsRawStartKey(): void
    {
        $a = new NormalEntity()->setAutofilledId('a')->setRequired('req');
        $itemA = [...$this->item('a'), 'required' => new AttributeValue(['S' => 'req'])];

        $this->dynamo->method('scan')->willReturn(self::scanOutput([$itemA]));
        $this->serializer->method('deserialize')->willReturn($a);

        // Only the key attributes survive: they are the ExclusiveStartKey to resume after this item.
        foreach ($this->reader->search(new ScanInput(NormalEntity::class)) as $key => $entity) {
            static::assertEquals($this->item('a'), $key);
            static::assertSame($a, $entity);
        }
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

        $cursor = new Cursor($this->item('cursor-id'))->encode();

        iterator_to_array($this->reader->search(new ScanInput(NormalEntity::class, cursor: $cursor)), false);
    }

    public function testSearchReadsABackwardCursorInReverse(): void
    {
        $output = self::queryOutput();
        $this->dynamo->expects(static::once())
            ->method('query')
            ->with(static::callback(static function (DynamoDbQueryInput $input): bool {
                static::assertFalse($input->getScanIndexForward());
                static::assertSame('cursor-id', ($input->getExclusiveStartKey()['autofilledId'] ?? null)?->getS());

                return true;
            }))
            ->willReturn($output);

        $cursor = new Cursor($this->item('cursor-id'), backward: true)->encode();

        iterator_to_array($this->reader->search(new QueryInput(NormalEntity::class, Filter::keyFilter(Filter::equals('autofilledId', 'x')), cursor: $cursor)), false);
    }

    public function testSearchRequestsTheNextPageOnlyOnceTheStreamReachesIt(): void
    {
        $a = new NormalEntity()->setAutofilledId('a')->setRequired('req');
        $b = new NormalEntity()->setAutofilledId('b')->setRequired('req');
        $itemA = $this->item('a');
        $itemB = $this->item('b');

        // An ArrayObject holder, so phpstan does not constant-fold what the callback records.
        /** @var \ArrayObject<int, DynamoDbQueryInput> $inputs */
        $inputs = new \ArrayObject();
        $pages = [self::queryOutput([$itemA], lastEvaluatedKey: $itemA), self::queryOutput([$itemB])];

        $this->dynamo->expects(static::exactly(2))->method('query')->willReturnCallback(
            static function (DynamoDbQueryInput $input) use ($inputs, &$pages): QueryOutput {
                $inputs->append($input);

                return array_shift($pages) ?? self::queryOutput();
            },
        );
        $this->serializer->method('deserialize')->willReturnCallback(
            static fn (EntityDefinition $definition, array $item): NormalEntity => $item === $itemA ? $a : $b,
        );

        $cursor = new Cursor($this->item('z'), backward: true)->encode();
        $search = $this->reader->search(new QueryInput(NormalEntity::class, Filter::keyFilter(Filter::equals('autofilledId', 'x')), cursor: $cursor));

        static::assertSame($a, $search->current());
        static::assertCount(1, $inputs, 'the next page must not be requested while the current one is read');

        $search->next();
        static::assertSame($b, $search->current());
        static::assertCount(2, $inputs);

        // The next page resumes after the current one, still reading backward.
        $next = $inputs[1];
        static::assertNotNull($next);
        static::assertSame('a', ($next->getExclusiveStartKey()['autofilledId'] ?? null)?->getS());
        static::assertFalse($next->getScanIndexForward());
    }

    public function testSearchWithoutAFilterAsksForOneItemPastTheLimit(): void
    {
        $this->dynamo->expects(static::once())
            ->method('scan')
            ->with(static::callback(static function (DynamoDbScanInput $input): bool {
                static::assertSame(3, $input->getLimit());

                return true;
            }))
            ->willReturn(self::scanOutput());

        iterator_to_array($this->reader->search(new ScanInput(NormalEntity::class, limit: 2)), false);
    }

    public function testSearchCountsALimitBelowOneAsOne(): void
    {
        $this->dynamo->expects(static::once())
            ->method('scan')
            ->with(static::callback(static function (DynamoDbScanInput $input): bool {
                static::assertSame(2, $input->getLimit());

                return true;
            }))
            ->willReturn(self::scanOutput());

        iterator_to_array($this->reader->search(new ScanInput(NormalEntity::class, limit: -1)), false);
    }

    public function testSearchWithAFilterReadsFullPages(): void
    {
        $this->dynamo->expects(static::once())
            ->method('scan')
            ->with(static::callback(static function (DynamoDbScanInput $input): bool {
                // DynamoDB filters after applying `Limit`, so a page limit would only mean more requests.
                static::assertNull($input->getLimit());

                return true;
            }))
            ->willReturn(self::scanOutput());

        iterator_to_array($this->reader->search(new ScanInput(NormalEntity::class, filter: Filter::equals('name', 'foo'), limit: 2)), false);
    }

    public function testSearchRejectsACursorOfAnotherKeySchema(): void
    {
        $this->dynamo->expects(static::never())->method('scan');

        $cursor = new Cursor(['tenantId' => new AttributeValue(['S' => 'x'])])->encode();

        $this->expectException(InvalidCursorException::class);
        iterator_to_array($this->reader->search(new ScanInput(NormalEntity::class, cursor: $cursor)), false);
    }

    public function testSearchRejectsABackwardCursorOnAScan(): void
    {
        $this->dynamo->expects(static::never())->method('scan');

        $cursor = new Cursor($this->item('cursor-id'), backward: true)->encode();

        $this->expectException(InvalidCursorException::class);
        iterator_to_array($this->reader->search(new ScanInput(NormalEntity::class, cursor: $cursor)), false);
    }

    public function testSearchRejectsAnUnregisteredClass(): void
    {
        $this->dynamo->expects(static::never())->method('scan');

        $this->expectException(UnknownEntityDefinitionException::class);
        iterator_to_array($this->reader->search(new ScanInput(OtherEntity::class)), false);
    }

    public function testSearchOnlyAsksDynamoDbOnceTheStreamIsRead(): void
    {
        $this->dynamo->expects(static::never())->method('scan');

        $this->reader->search(new ScanInput(NormalEntity::class));
    }

    public function testCountRejectsAnUnregisteredClass(): void
    {
        $this->dynamo->expects(static::never())->method('scan');

        $this->expectException(UnknownEntityDefinitionException::class);
        $this->reader->count(new ScanInput(OtherEntity::class));
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

        static::assertSame(8, $this->reader->count(new ScanInput(NormalEntity::class)));
    }

    public function testCountIgnoresTheLimitAndReadsFullPages(): void
    {
        $this->dynamo->expects(static::once())
            ->method('scan')
            ->with(static::callback(static function (DynamoDbScanInput $input): bool {
                // Every match is counted, so a page limit would only split the count into more requests.
                static::assertNull($input->getLimit());

                return true;
            }))
            ->willReturn(self::scanOutput(count: 3));

        static::assertSame(3, $this->reader->count(new ScanInput(NormalEntity::class, limit: 1)));
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

        $result = iterator_to_array($this->reader->get(new GetInput([new Key(NormalEntity::class, 'a')], true)), false);

        static::assertSame([$entity], $result);
    }

    public function testGetSingleKeyYieldsNothingWhenTheItemIsAbsent(): void
    {
        $this->stubKeySerialization();

        $output = self::getItemOutput(); // GetItem returns [] when the key matches no item

        $this->dynamo->expects(static::once())->method('getItem')->willReturn($output);

        // An empty item answers no key, so there is nothing to deserialize and nothing to yield.
        $this->serializer->expects(static::never())->method('deserialize');

        $result = iterator_to_array($this->reader->get(new GetInput([new Key(NormalEntity::class, 'missing')])), false);

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
            $this->reader->get(new GetInput([new Key(NormalEntity::class, 'a'), new Key(NormalEntity::class, 'b')])),
            false,
        );

        static::assertSame([$a, $b], $result);
    }

    /**
     * DynamoDB answers only part of a batch under throttling, so the keys it leaves over are asked for again as they are.
     */
    public function testGetAsksAgainForTheKeysABatchLeavesUnprocessed(): void
    {
        $a = new NormalEntity()->setAutofilledId('a')->setRequired('req');
        $b = new NormalEntity()->setAutofilledId('b')->setRequired('req');
        $itemA = $this->item('a');
        $itemB = $this->item('b');
        $leftOver = [self::TABLE => new KeysAndAttributes(['Keys' => [$itemB], 'ConsistentRead' => false])];

        $this->stubKeySerialization();

        // An ArrayObject holder, so phpstan does not constant-fold what the callback records.
        /** @var \ArrayObject<int, mixed> $requestItemsPerCall */
        $requestItemsPerCall = new \ArrayObject();
        $this->dynamo->expects(static::exactly(2))
            ->method('batchGetItem')
            ->willReturnCallback(
                /** @param array{RequestItems: mixed} $input */
                static function (array $input) use ($requestItemsPerCall, $itemA, $itemB, $leftOver): BatchGetItemOutput {
                    $requestItemsPerCall->append($input['RequestItems']);

                    return \count($requestItemsPerCall) === 1
                        ? self::batchGetItemOutput([self::TABLE => [$itemA]], $leftOver)
                        : self::batchGetItemOutput([self::TABLE => [$itemB]]);
                },
            );

        $this->serializer->method('deserialize')->willReturnCallback(
            static fn (EntityDefinition $definition, array $item): NormalEntity => $item === $itemA ? $a : $b,
        );

        $result = iterator_to_array(
            $this->reader->get(new GetInput([new Key(NormalEntity::class, 'a'), new Key(NormalEntity::class, 'b')])),
            false,
        );

        static::assertSame([$a, $b], $result);
        static::assertSame($leftOver, $requestItemsPerCall[1]);
    }

    public function testGetKeysSpanningTablesIssueOneBatchGetItemAcrossBothTables(): void
    {
        $normal = new NormalEntity()->setAutofilledId('a')->setRequired('req');
        $other = new OtherEntity()->setOtherId('o');
        $normalItem = $this->item('a');
        $otherItem = ['otherId' => new AttributeValue(['S' => 'o'])];

        // A second entity, registered alongside `normal`, with its own physical table name.
        $otherDefinition = OtherEntity::createDefinition();

        $reader = $this->createReader(new EntityDefinitionRegistry([
                $this->definition->getName() => $this->definition,
                $otherDefinition->getName() => $otherDefinition,
            ]));

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
                ->withKey(new Key(NormalEntity::class, 'a'))
                ->withKey(new Key(OtherEntity::class, 'o'))),
            false,
        );

        // Both tables' entities are yielded, each deserialized with the definition its keys were built from.
        static::assertSame([$normal, $other], $entities);
    }

    public function testRefreshDeserializesEachReturnedRowIntoTheEntityItBelongsTo(): void
    {
        $a = new NormalEntity()->setAutofilledId('a')->setRequired('req');
        $b = new NormalEntity()->setAutofilledId('b')->setRequired('req');
        $itemA = $this->item('a');
        $itemB = $this->item('b');

        $this->stubEntityKeySerialization();

        $this->dynamo->expects(static::once())
            ->method('batchGetItem')
            ->with(static::callback(static function (array $args): bool {
                static::assertTrue($args['RequestItems'][self::TABLE]['ConsistentRead']);
                static::assertCount(2, $args['RequestItems'][self::TABLE]['Keys']);

                return true;
            }))
            // Rows come back in no particular order.
            ->willReturn(self::batchGetItemOutput([self::TABLE => [$itemB, $itemA]]));

        $deserialized = [];
        $this->serializer->expects(static::exactly(2))->method('deserialize')->willReturnCallback(
            static function (EntityDefinition $definition, array $item, ?AbstractEntity $entity) use (&$deserialized): ?AbstractEntity {
                $deserialized[] = [$item, $entity];

                return $entity;
            },
        );

        $this->reader->refresh(new RefreshInput([$a, $b], consistentRead: true));

        static::assertSame([[$itemB, $b], [$itemA, $a]], $deserialized);
    }

    public function testRefreshOfASingleEntityIssuesOneGetItemIntoThatEntity(): void
    {
        $entity = new NormalEntity()->setAutofilledId('a')->setRequired('req');
        $item = $this->item('a');

        $this->stubEntityKeySerialization();

        $this->dynamo->expects(static::once())
            ->method('getItem')
            ->with(static::callback(static function (array $request): bool {
                // Eventually consistent unless asked otherwise, like get().
                static::assertFalse($request['ConsistentRead']);

                return true;
            }))
            ->willReturn(self::getItemOutput($item));
        $this->dynamo->expects(static::never())->method('batchGetItem');

        $this->serializer->expects(static::once())->method('deserialize')->with($this->definition, $item, $entity)->willReturn($entity);

        $this->reader->refresh(new RefreshInput([$entity]));
    }

    public function testRefreshLeavesAnEntityWithoutARowUntouched(): void
    {
        $a = new NormalEntity()->setAutofilledId('a')->setRequired('req');
        $b = new NormalEntity()->setAutofilledId('b')->setRequired('req');
        $itemA = $this->item('a');

        $this->stubEntityKeySerialization();

        // `b`'s row is gone, so only `a`'s comes back.
        $this->dynamo->method('batchGetItem')->willReturn(self::batchGetItemOutput([self::TABLE => [$itemA]]));
        $this->serializer->expects(static::once())->method('deserialize')->with($this->definition, $itemA, $a)->willReturn($a);

        $this->reader->refresh(new RefreshInput([$a, $b]));
    }

    public function testRefreshWithoutEntitiesReadsNothing(): void
    {
        $this->dynamo->expects(static::never())->method('batchGetItem');
        $this->dynamo->expects(static::never())->method('getItem');

        $this->reader->refresh(new RefreshInput([]));
    }

    public function testSearchRefusesAnIndexTheDefinitionDoesNotDeclare(): void
    {
        $this->dynamo->expects(static::never())->method('query');

        $this->expectException(UnknownIndexException::class);

        iterator_to_array($this->reader->search(new QueryInput(NormalEntity::class, Filter::keyFilter(Filter::equals('name', 'x')), index: 'someIndex')));
    }

    public function testCountRefusesAnIndexTheDefinitionDoesNotDeclare(): void
    {
        $this->dynamo->expects(static::never())->method('query');

        $this->expectException(UnknownIndexException::class);

        $this->reader->count(new QueryInput(NormalEntity::class, Filter::keyFilter(Filter::equals('name', 'x')), index: 'someIndex'));
    }

    /**
     * The key condition is checked against the key of the index queried, not the table's.
     */
    public function testSearchRefusesAKeyConditionOnTheTableKeyForAnIndexQuery(): void
    {
        $this->dynamo->expects(static::never())->method('query');

        $this->expectException(InvalidKeyConditionException::class);
        $this->expectExceptionMessage('index "someIndex"');

        iterator_to_array($this->indexedReader()->search(new QueryInput(NormalEntity::class, Filter::keyFilter(Filter::equals('autofilledId', 'a')), index: 'someIndex')));
    }

    public function testGetReadsAKeyGivenTwiceOnce(): void
    {
        $a = new NormalEntity()->setAutofilledId('a')->setRequired('req');
        $b = new NormalEntity()->setAutofilledId('b')->setRequired('req');
        $itemA = $this->item('a');
        $itemB = $this->item('b');

        $this->stubKeySerialization();

        // BatchGetItem refuses a request that lists a key twice
        $this->dynamo->expects(static::once())
            ->method('batchGetItem')
            ->with(static::callback(static function (array $args): bool {
                static::assertCount(2, $args['RequestItems'][self::TABLE]['Keys']);

                return true;
            }))
            ->willReturn(self::batchGetItemOutput([self::TABLE => [$itemA, $itemB]]));

        $this->serializer->method('deserialize')->willReturnCallback(
            static fn (EntityDefinition $definition, array $item): NormalEntity => $item === $itemA ? $a : $b,
        );

        $result = iterator_to_array(
            $this->reader->get(new GetInput([new Key(NormalEntity::class, 'a'), new Key(NormalEntity::class, 'b'), new Key(NormalEntity::class, 'a')])),
            false,
        );

        static::assertSame([$a, $b], $result);
    }

    public function testGetOfOneKeyGivenTwiceIssuesOneGetItem(): void
    {
        $entity = new NormalEntity()->setAutofilledId('a')->setRequired('req');

        $this->stubKeySerialization();

        $this->dynamo->expects(static::once())->method('getItem')->willReturn(self::getItemOutput($this->item('a')));
        $this->dynamo->expects(static::never())->method('batchGetItem');
        $this->serializer->expects(static::once())->method('deserialize')->willReturn($entity);

        $result = iterator_to_array($this->reader->get(new GetInput([new Key(NormalEntity::class, 'a'), new Key(NormalEntity::class, 'a')])), false);

        static::assertSame([$entity], $result);
    }

    /**
     * The two copies would otherwise land in different requests of 100, and the entity come back twice.
     */
    public function testGetReadsAKeyOnceAcrossRequests(): void
    {
        $this->stubKeySerialization();

        $keys = array_map(static fn (int $i): Key => new Key(NormalEntity::class, "id-{$i}"), range(0, 99));
        $keys[] = new Key(NormalEntity::class, 'id-0');

        $this->dynamo->expects(static::once())
            ->method('batchGetItem')
            ->with(static::callback(static function (array $args): bool {
                static::assertCount(100, $args['RequestItems'][self::TABLE]['Keys']);

                return true;
            }))
            ->willReturn(self::batchGetItemOutput([self::TABLE => []]));

        iterator_to_array($this->reader->get(new GetInput($keys)), false);
    }

    public function testRefreshReadsTheRowOfSeveralInstancesWithOneKeyIntoEachOfThem(): void
    {
        $first = new NormalEntity()->setAutofilledId('a')->setRequired('req');
        $second = new NormalEntity()->setAutofilledId('a')->setRequired('req');
        $item = $this->item('a');

        $this->stubEntityKeySerialization();

        $this->dynamo->expects(static::once())->method('getItem')->willReturn(self::getItemOutput($item));
        $this->dynamo->expects(static::never())->method('batchGetItem');

        $deserialized = [];
        $this->serializer->method('deserialize')->willReturnCallback(
            static function (EntityDefinition $definition, array $row, ?AbstractEntity $entity) use (&$deserialized): ?AbstractEntity {
                $deserialized[] = $entity;

                return $entity;
            },
        );

        $this->reader->refresh(new RefreshInput([$first, $second]));

        static::assertSame([$first, $second], $deserialized);
    }

    public function testRefreshOfOneInstanceGivenTwiceReadsItOnce(): void
    {
        $entity = new NormalEntity()->setAutofilledId('a')->setRequired('req');

        $this->stubEntityKeySerialization();

        $this->dynamo->expects(static::once())->method('getItem')->willReturn(self::getItemOutput($this->item('a')));
        $this->serializer->expects(static::once())->method('deserialize')->willReturn($entity);

        $this->reader->refresh(new RefreshInput([$entity, $entity]));
    }

    private function registry(): EntityDefinitionRegistry
    {
        return new EntityDefinitionRegistry([$this->definition->getName() => $this->definition]);
    }

    /**
     * A reader over a definition that declares `someIndex`, keyed by `name`.
     */
    private function indexedReader(): ReaderClient
    {
        $definition = NormalEntity::createDefinition(indexes: ['someIndex' => new IndexSchema('someIndex', 'name')]);

        return $this->createReader(new EntityDefinitionRegistry([$definition->getName() => $definition]));
    }

    /**
     * A reader over the mocked client, wired as `config/services.php` wires it.
     */
    private function createReader(EntityDefinitionRegistry $registry): ReaderClient
    {
        return new ReaderClient($this->dynamo, $this->serializer, new ReadRequestFactory($this->serializer, new FilterCompiler(), $registry));
    }

    /**
     * Stubs the serializer's key serialization used by {@see ReaderClient} to turn a {@see Key} into
     * the DynamoDB key map for `GetItem`/`BatchGetItem` requests.
     */
    private function stubKeySerialization(): void
    {
        $this->serializer->method('serializeKey')->willReturnCallback(
            static fn (EntityDefinition $definition, Key $key): SerializedKeyResult => SerializedKeyResult::fromItem($definition, self::serializedKey($definition, $key)->getFields()),
        );
    }

    /**
     * Stubs key serialization of an entity, whose hash {@see ReaderClient::refresh()} matches rows by.
     */
    private function stubEntityKeySerialization(): void
    {
        $this->serializer->method('serializeKey')->willReturnCallback(
            static fn (EntityDefinition $definition, NormalEntity $entity): SerializedKeyResult => SerializedKeyResult::fromItem($definition, [
                'autofilledId' => new AttributeValue(['S' => $entity->getAutofilledId()]),
            ]),
        );
    }

    /**
     * @param EntityDefinition<AbstractEntity> $definition
     * @param Key<AbstractEntity> $key
     */
    private static function serializedKey(EntityDefinition $definition, Key $key): SerializedResult
    {
        $keySchema = $definition->getKeySchema();
        $fields = [$keySchema->hashKey => $key->hashValue];
        if ($keySchema->rangeKey !== null) {
            $fields[$keySchema->rangeKey] = $key->rangeValue;
        }

        $serialized = [];
        foreach ($fields as $name => $value) {
            $path = FieldPath::parse($definition, $name);
            static::assertIsString($value);

            $serialized[$name] = new SerializedFieldResult($path, new AttributeValue(['S' => $value]));
        }

        return new SerializedResult($serialized, $fields, NormalizerOperation::Key);
    }

    /**
     * A raw async-aws item map, the shape `ReaderClient::search()` reads off each page.
     *
     * @return array<string, AttributeValue>
     */
    private function item(string $id): array
    {
        return ['autofilledId' => new AttributeValue(['S' => $id])];
    }
}
