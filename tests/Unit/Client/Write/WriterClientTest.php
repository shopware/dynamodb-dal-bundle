<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Client\Write;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Client\Key;
use Shopware\DynamodbDalBundle\Client\Input\BatchWriteInput;
use Shopware\DynamodbDalBundle\Client\Input\DeleteInput;
use Shopware\DynamodbDalBundle\Client\Input\InsertInput;
use Shopware\DynamodbDalBundle\Client\Input\PutInput;
use Shopware\DynamodbDalBundle\Client\Input\Refresh;
use Shopware\DynamodbDalBundle\Client\Input\TransactWriteInput;
use Shopware\DynamodbDalBundle\Client\Input\UpdateInput;
use Shopware\DynamodbDalBundle\Client\Input\UpsertInput;
use Shopware\DynamodbDalBundle\Client\Output\UpsertOutcome;
use Shopware\DynamodbDalBundle\Client\Write\WriteRequestFactory;
use Shopware\DynamodbDalBundle\Client\Write\WriterClient;
use Shopware\DynamodbDalBundle\Expression\FilterCompiler;
use Shopware\DynamodbDalBundle\Expression\UpdateCompiler;
use Shopware\DynamodbDalBundle\Expression\Filter;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\EntityDefinitionRegistry;
use Shopware\DynamodbDalBundle\Definition\FieldPath;
use Shopware\DynamodbDalBundle\Client\Read\ReaderClient;
use Shopware\DynamodbDalBundle\Client\Read\ReadRequestFactory;
use Shopware\DynamodbDalBundle\Exception\ConditionEmptyException;
use Shopware\DynamodbDalBundle\Exception\DeserializationException;
use Shopware\DynamodbDalBundle\Exception\DuplicateKeyException;
use Shopware\DynamodbDalBundle\Exception\DenormalizationException;
use Shopware\DynamodbDalBundle\Exception\EntityOutOfSyncException;
use Shopware\DynamodbDalBundle\Exception\FieldMissingDeserializedValueException;
use Shopware\DynamodbDalBundle\Exception\UnknownEntityDefinitionException;
use Shopware\DynamodbDalBundle\Serializer\AbstractNormalizer;
use Shopware\DynamodbDalBundle\Serializer\NormalizerOperation;
use Shopware\DynamodbDalBundle\Exception\UpdateEmptyException;
use Shopware\DynamodbDalBundle\Exception\UpsertContentionException;
use Shopware\DynamodbDalBundle\Expression\Update;
use Shopware\DynamodbDalBundle\Expression\Update\UpdateExpression;
use Shopware\DynamodbDalBundle\Serializer\SerializedFieldResult;
use Shopware\DynamodbDalBundle\Serializer\SerializedKeyResult;
use Shopware\DynamodbDalBundle\Serializer\SerializedResult;
use Shopware\DynamodbDalBundle\Serializer\Serializer;
use Shopware\DynamodbDalBundle\Tests\Unit\Definition\Fixtures\MapDefinition;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\NormalEntity;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\OtherEntity;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\PrefixingNormalizer;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\RecordingNormalizer;
use AsyncAws\Core\AwsError\AwsError;
use AsyncAws\Core\Test\Http\SimpleMockedResponse;
use AsyncAws\Core\Test\ResultMockFactory;
use AsyncAws\DynamoDb\DynamoDbClient;
use AsyncAws\DynamoDb\Enum\ReturnValue;
use AsyncAws\DynamoDb\Enum\ReturnValuesOnConditionCheckFailure;
use AsyncAws\DynamoDb\Exception\ConditionalCheckFailedException;
use AsyncAws\DynamoDb\Exception\TransactionCanceledException;
use AsyncAws\DynamoDb\Result\BatchGetItemOutput;
use AsyncAws\DynamoDb\Result\BatchWriteItemOutput;
use AsyncAws\DynamoDb\Result\DeleteItemOutput;
use AsyncAws\DynamoDb\Result\GetItemOutput;
use AsyncAws\DynamoDb\Result\PutItemOutput;
use AsyncAws\DynamoDb\Result\TransactWriteItemsOutput;
use AsyncAws\DynamoDb\Result\UpdateItemOutput;
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
 * real DynamoDB: empty batches and transactions, the order a transaction sends its operations in, and the
 * batch-write retry loop (which only runs when the service returns UnprocessedItems). The DynamoDB client is
 * mocked; write results are produced with {@see ResultMockFactory} so their final `resolve()` works. A real
 * {@see NormalEntity} definition and {@see FilterCompiler} and {@see UpdateCompiler} are used so condition filters compile for real.
 */
#[AllowMockObjectsWithoutExpectations]
#[CoversClass(WriterClient::class)]
class WriterClientTest extends TestCase
{
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

        $this->serializer->method('serialize')->willReturnCallback(
            fn (EntityDefinition $definition, mixed $fields, NormalizerOperation $operation): SerializedResult => $this->serializedResult($operation, $fields),
        );
        // Each entity or key its own, since a transaction refuses a key named twice
        $this->serializer->method('serializeKey')->willReturnCallback(
            static function (EntityDefinition $definition, AbstractEntity|Key $key): SerializedKeyResult {
                $hashKey = $definition->getKeySchema()->hashKey;
                $value = $key instanceof Key ? $key->hashValue : ($key->getVars()[$hashKey] ?? null);
                static::assertIsString($value);

                return SerializedKeyResult::fromItem($definition, [$hashKey => new AttributeValue(['S' => $value])]);
            },
        );
        // The definitions here carry no shape-changing normalizer, so (de)normalizing is the identity —
        // stubbed rather than mocked away, since an update and the write-back run through it.
        $this->serializer->method('normalize')->willReturnArgument(1);
        $this->serializer->method('denormalize')->willReturnArgument(1);
        $this->serializer->method('assign')->willReturnCallback(static fn (EntityDefinition $definition, AbstractEntity $entity, array $fields): AbstractEntity => $entity->setVars($fields));

        $registry = new EntityDefinitionRegistry([$this->definition->getName() => $this->definition]);
        $this->writer = $this->createWriter($this->serializer, $registry);
    }

    public function testAnEmptyBatchSendsNothing(): void
    {
        $this->client->expects(static::never())->method('batchWriteItem');

        $this->writer->batchWrite(new BatchWriteInput());
    }

    public function testAnEmptyTransactionSendsNothing(): void
    {
        $this->client->expects(static::never())->method('transactWriteItems');
        $this->client->expects(static::never())->method('batchGetItem');

        $this->writer->transactWrite(new TransactWriteInput());
    }

    public function testPutRejectsAnUnregisteredClass(): void
    {
        $this->client->expects(static::never())->method('putItem');

        $this->expectException(UnknownEntityDefinitionException::class);
        $this->writer->put(new PutInput(new OtherEntity()->setOtherId('o')));
    }

    public function testInsertRejectsAnUnregisteredClass(): void
    {
        $this->client->expects(static::never())->method('putItem');

        $this->expectException(UnknownEntityDefinitionException::class);
        $this->writer->insert(new InsertInput(new OtherEntity()->setOtherId('o')));
    }

    /**
     * The key being free is the insert's only condition, so its failure needs no stored item to tell why, and nothing the
     * normalizer generated for the put goes back onto the entity.
     */
    public function testAnInsertOfAStoredItemWritesNothingAndLeavesItsEntityAlone(): void
    {
        $normalizer = new RecordingNormalizer();

        $this->client->expects(static::once())
            ->method('putItem')
            ->with(static::callback(static fn (array $args): bool => ($args['ConditionExpression'] ?? null) === 'NOT attribute_exists(#autofilledId)'
                && !isset($args['ReturnValuesOnConditionCheckFailure'])))
            ->willThrowException(self::conditionalCheckFailed());

        static::assertFalse($this->normalizingWriter($normalizer)->insert(new InsertInput($this->entity('a'))));

        static::assertSame([], $this->denormalizedAs($normalizer));
    }

    public function testAnInsertIsDenormalizedAsThePutThatWroteIt(): void
    {
        $normalizer = new RecordingNormalizer();

        $this->client->expects(static::once())
            ->method('putItem')
            ->willReturn(ResultMockFactory::create(PutItemOutput::class));

        static::assertTrue($this->normalizingWriter($normalizer)->insert(new InsertInput($this->entity('a'))));

        static::assertSame([NormalizerOperation::Put], $this->denormalizedAs($normalizer));
    }

    /**
     * Without an item on the failure, the existence check failed, so the entity is left as it is rather than taking a row.
     */
    public function testAnUpdateOfAMissingItemWritesNothingAndLeavesItsEntityAlone(): void
    {
        $this->client->expects(static::once())
            ->method('updateItem')
            ->with(static::callback(static fn (array $args): bool => ($args['ReturnValuesOnConditionCheckFailure'] ?? null) === ReturnValuesOnConditionCheckFailure::ALL_OLD))
            ->willThrowException(self::conditionalCheckFailed());

        $entity = $this->entity('a');
        static::assertFalse($this->writer->update(new UpdateInput($entity, ['name' => 'after'])));

        static::assertSame('req', $entity->getRequired());
        static::assertFalse(isset($entity->getVars()['name']));
    }

    /**
     * The stored item comes back with the failure, so it is the update's own condition that failed on it.
     */
    public function testAnUpdateWhoseConditionFailsOnTheStoredItemIsThrown(): void
    {
        $failure = self::conditionalCheckFailed(['autofilledId' => ['S' => 'a']]);

        $this->client->expects(static::once())->method('updateItem')->willThrowException($failure);

        try {
            $this->writer->update(new UpdateInput(new Key(NormalEntity::class, 'a'), ['name' => 'after'], Filter::notExists('name')));
            static::fail('The condition fails on the stored item');
        } catch (ConditionalCheckFailedException $exception) {
            static::assertSame($failure, $exception);
        }
    }

    public function testALoneDeleteIsConditionedOnItsItemExisting(): void
    {
        $this->client->expects(static::once())
            ->method('deleteItem')
            ->with(static::callback(static fn (array $args): bool => ($args['ConditionExpression'] ?? null) === 'attribute_exists(#autofilledId)'
                && ($args['ReturnValuesOnConditionCheckFailure'] ?? null) === ReturnValuesOnConditionCheckFailure::ALL_OLD))
            ->willReturn(ResultMockFactory::create(DeleteItemOutput::class));

        static::assertTrue($this->writer->delete(new DeleteInput(new Key(NormalEntity::class, 'a'))));
    }

    public function testALoneDeleteConditionThatChecksNothingIsRefusedBeforeAnyRequest(): void
    {
        $this->client->expects(static::never())->method('deleteItem');

        $this->expectException(ConditionEmptyException::class);

        $this->writer->delete(new DeleteInput(new Key(NormalEntity::class, 'a'), Filter::equalsAny('name', [])));
    }

    public function testADeleteOfAMissingItemDeletesNothing(): void
    {
        $this->client->expects(static::once())->method('deleteItem')->willThrowException(self::conditionalCheckFailed());

        static::assertFalse($this->writer->delete(new DeleteInput(new Key(NormalEntity::class, 'a'), Filter::exists('name'))));
    }

    /**
     * The stored item comes back with the failure, so it is the delete's own condition that failed on it.
     */
    public function testADeleteWhoseConditionFailsOnTheStoredItemIsThrown(): void
    {
        $failure = self::conditionalCheckFailed(['autofilledId' => ['S' => 'a']]);

        $this->client->expects(static::once())->method('deleteItem')->willThrowException($failure);

        try {
            $this->writer->delete(new DeleteInput(new Key(NormalEntity::class, 'a'), Filter::notExists('name')));
            static::fail('The condition fails on the stored item');
        } catch (ConditionalCheckFailedException $exception) {
            static::assertSame($failure, $exception);
        }
    }

    public function testUpdateRejectsAnUnregisteredClass(): void
    {
        $this->client->expects(static::never())->method('updateItem');

        $this->expectException(UnknownEntityDefinitionException::class);
        $this->writer->update(new UpdateInput(new Key(OtherEntity::class, 'o'), ['otherId' => 'o']));
    }

    public function testDeleteRejectsAnUnregisteredClass(): void
    {
        $this->client->expects(static::never())->method('deleteItem');

        $this->expectException(UnknownEntityDefinitionException::class);
        $this->writer->delete(new DeleteInput(new Key(OtherEntity::class, 'o')));
    }

    /**
     * What a write serialized is the row's shape. Applied back unchanged it would land on a property typed
     * for the other side of the normalizer — harmless while one only fills missing values in, a type error
     * once one converts between representations. A real serializer, so the round trip is the real one.
     */
    public function testPutWritesTheEntitysShapeRatherThanTheStoredOne(): void
    {
        $writer = $this->normalizingWriter(new PrefixingNormalizer());

        $this->client->expects(static::once())
            ->method('putItem')
            ->willReturn(ResultMockFactory::create(PutItemOutput::class));

        $entity = $this->entity('a')->setName('test');

        $writer->put(new PutInput($entity));

        static::assertSame('test', $entity->getName());
    }

    /**
     * A transaction returns no item, so the fields an update wrote are turned back into entity values instead,
     * and the normalizer is told which write they come from rather than taking them for a row it read.
     */
    public function testATransactionalUpdateIsDenormalizedAsTheUpdateThatWroteIt(): void
    {
        $normalizer = new RecordingNormalizer();
        $writer = $this->normalizingWriter($normalizer);

        $this->client->expects(static::once())
            ->method('transactWriteItems')
            ->willReturn(ResultMockFactory::create(TransactWriteItemsOutput::class));

        $writer->transactWrite(new TransactWriteInput()->with(
            new UpdateInput($this->entity('a'), ['name' => 'one']),
            new UpdateInput($this->entity('b'), ['name' => 'two']),
        ));

        static::assertSame([
            ['normalize', NormalizerOperation::Update, ['name' => 'one']],
            ['normalize', NormalizerOperation::Key, ['autofilledId' => 'a']],
            ['normalize', NormalizerOperation::Update, ['name' => 'two']],
            ['normalize', NormalizerOperation::Key, ['autofilledId' => 'b']],
            ['denormalize', NormalizerOperation::Update, ['name' => 'one']],
            ['denormalize', NormalizerOperation::Update, ['name' => 'two']],
        ], $normalizer->calls);
    }

    /**
     * A put returns no item either, so the fields it sent go back onto the entity as the put's, whichever
     * request carries them.
     */
    public function testALonePutIsDenormalizedAsThePutThatWroteIt(): void
    {
        $normalizer = new RecordingNormalizer();

        $this->client->expects(static::once())
            ->method('putItem')
            ->willReturn(ResultMockFactory::create(PutItemOutput::class));

        $this->normalizingWriter($normalizer)->put(new PutInput($this->entity('a')));

        static::assertSame([NormalizerOperation::Put], $this->denormalizedAs($normalizer));
    }

    public function testABatchOfPutsIsDenormalizedAsThePutsThatWroteThem(): void
    {
        $normalizer = new RecordingNormalizer();

        $this->client->expects(static::once())
            ->method('batchWriteItem')
            ->willReturn(ResultMockFactory::create(BatchWriteItemOutput::class, ['unprocessedItems' => []]));

        $this->normalizingWriter($normalizer)->batchWrite(
            new BatchWriteInput()->withPut($this->entity('a'), $this->entity('b')),
        );

        static::assertSame([NormalizerOperation::Put, NormalizerOperation::Put], $this->denormalizedAs($normalizer));
    }

    public function testATransactionOfPutsIsDenormalizedAsThePutsThatWroteThem(): void
    {
        $normalizer = new RecordingNormalizer();

        $this->client->expects(static::once())
            ->method('transactWriteItems')
            ->willReturn(ResultMockFactory::create(TransactWriteItemsOutput::class));

        $this->normalizingWriter($normalizer)->transactWrite(new TransactWriteInput()->with(
            new PutInput($this->entity('a'), Filter::notExists('autofilledId')),
            new PutInput($this->entity('b')),
        ));

        static::assertSame([NormalizerOperation::Put, NormalizerOperation::Put], $this->denormalizedAs($normalizer));
    }

    /**
     * A lone update keyed by an entity asks for the whole new item, so what goes back onto the entity is a row
     * DynamoDB returned, every field present, rather than the fields the update sent.
     */
    public function testALoneUpdateKeyedByAnEntityIsDenormalizedAsTheItemItReturns(): void
    {
        $normalizer = new RecordingNormalizer();

        $this->client->expects(static::once())
            ->method('updateItem')
            ->willReturn(ResultMockFactory::create(UpdateItemOutput::class, ['attributes' => [
                'autofilledId' => new AttributeValue(['S' => 'a']),
                'required' => new AttributeValue(['S' => 'req']),
                'name' => new AttributeValue(['S' => 'after']),
            ]]));

        $entity = $this->entity('a');
        $this->normalizingWriter($normalizer)->update(new UpdateInput($entity, ['name' => 'after']));

        static::assertSame([NormalizerOperation::Read], $this->denormalizedAs($normalizer));
        static::assertSame('after', $entity->getName());
    }

    /**
     * A failed transaction reports one cancellation reason per operation, by position, so the operations go out in
     * the order they were added, whichever entity class each one is on.
     */
    public function testATransactionSendsItsOperationsInTheOrderTheyWereAdded(): void
    {
        $this->client->expects(static::once())
            ->method('transactWriteItems')
            ->with(static::callback(static function (array $args): bool {
                $items = $args['TransactItems'] ?? [];
                static::assertIsArray($items);
                static::assertCount(3, $items);

                static::assertInstanceOf(TransactWriteItem::class, $items[0]);
                static::assertNotNull($items[0]->getPut());
                static::assertInstanceOf(TransactWriteItem::class, $items[1]);
                static::assertSame('other-physical', $items[1]->getDelete()?->getTableName());
                static::assertInstanceOf(TransactWriteItem::class, $items[2]);
                static::assertNotNull($items[2]->getUpdate());

                return true;
            }))
            ->willReturn(ResultMockFactory::create(TransactWriteItemsOutput::class));

        $entity = $this->entity('a');

        $this->twoTableWriter()->transactWrite(
            new TransactWriteInput()
                ->with(new PutInput($entity, Filter::notExists('autofilledId')))
                ->with(new DeleteInput(new Key(OtherEntity::class, 'o')))
                ->with(new UpdateInput(new Key(NormalEntity::class, 'b'), ['name' => 'two'])),
        );

        static::assertSame(self::serializedId('a'), $entity->getAutofilledId());
    }

    /**
     * `BatchWriteItem`'s limit of 25 counts requests across tables, so one request carries every table's share.
     */
    public function testABatchSpanningTablesSendsThemInOneRequest(): void
    {
        $this->client->expects(static::once())
            ->method('batchWriteItem')
            ->with(static::callback(static function (array $args): bool {
                static::assertSame(['normal', 'other-physical'], array_keys($args['RequestItems'] ?? []));

                return true;
            }))
            ->willReturn(ResultMockFactory::create(BatchWriteItemOutput::class, ['unprocessedItems' => []]));

        $this->twoTableWriter()->batchWrite(
            new BatchWriteInput()
                ->withPut($this->entity('a'))
                ->withDelete(new Key(OtherEntity::class, 'o')),
        );
    }

    /**
     * A condition that checks nothing would let the write through unconditionally, so it is refused instead.
     */
    public function testAConditionThatChecksNothingIsRefusedBeforeAnyRequest(): void
    {
        $this->client->expects(static::never())->method('putItem');

        $this->expectException(ConditionEmptyException::class);

        $this->writer->put(new PutInput($this->entity('a'), Filter::equalsAny('name', [])));
    }

    /**
     * An update's own existence check would otherwise stand in for the condition, and hide that it checks nothing.
     */
    public function testAnUpdateConditionThatChecksNothingIsRefusedBeforeAnyRequest(): void
    {
        $this->client->expects(static::never())->method('updateItem');

        $this->expectException(ConditionEmptyException::class);

        $this->writer->update(new UpdateInput(new Key(NormalEntity::class, 'a'), ['name' => 'after'], Filter::and()));
    }

    /**
     * The existence check and the caller's condition are compiled apart, then joined as `Filter::and()` joins them.
     */
    public function testUpdateJoinsItsConditionWithTheExistenceCheck(): void
    {
        $this->client->expects(static::once())
            ->method('updateItem')
            ->with(static::callback(static function (array $args): bool {
                static::assertSame('attribute_exists(#autofilledId) AND (#name = :f_1_0_name OR #name = :f_1_1_name)', $args['ConditionExpression'] ?? null);

                return true;
            }))
            ->willReturn(ResultMockFactory::create(UpdateItemOutput::class));

        $this->writer->update(new UpdateInput(
            new Key(NormalEntity::class, 'a'),
            ['required' => 'req'],
            Filter::or(Filter::equals('name', 'one'), Filter::equals('name', 'two')),
        ));
    }

    /**
     * An update names only some fields, so the returned row is the only thing that can say what the entity
     * now looks like.
     */
    public function testUpdateKeyedByAnEntityAsksForTheWholeNewItemAndAppliesIt(): void
    {
        $entity = $this->entity('a');
        $item = ['autofilledId' => new AttributeValue(['S' => 'refreshed'])];

        $this->client->expects(static::once())
            ->method('updateItem')
            ->with(static::callback(static fn (array $args): bool => ($args['ReturnValues'] ?? null) === ReturnValue::ALL_NEW))
            ->willReturn(ResultMockFactory::create(UpdateItemOutput::class, ['attributes' => $item]));

        // The returned row goes onto the entity the caller passed in, not onto a new instance.
        $this->serializer->expects(static::once())
            ->method('deserialize')
            ->with($this->definition, $item, $entity);

        $this->writer->update(new UpdateInput($entity, ['name' => 'after']));
    }

    /**
     * Nothing to apply the row to, so nothing is asked for — ALL_NEW costs no capacity, but it does cost
     * response bytes.
     */
    public function testUpdateKeyedByAnIndexAsksForNothingBack(): void
    {
        $this->client->expects(static::once())
            ->method('updateItem')
            ->with(static::callback(static fn (array $args): bool => !\array_key_exists('ReturnValues', $args)))
            ->willReturn(ResultMockFactory::create(UpdateItemOutput::class));

        $this->serializer->expects(static::never())->method('deserialize');

        $this->writer->update(new UpdateInput(new Key(NormalEntity::class, 'a'), ['name' => 'after']));
    }

    /**
     * A whole attribute is written with exactly the value that was sent, so the entity is filled from the
     * update's own fields and the transaction is not followed by a read at all.
     */
    public function testUpdateOfSeveralAppliesWholeAttributesWithoutAReadback(): void
    {
        $this->client->expects(static::once())
            ->method('transactWriteItems')
            ->willReturn(ResultMockFactory::create(TransactWriteItemsOutput::class));

        $this->client->expects(static::never())->method('batchGetItem');

        $entityA = $this->entity('a');
        $entityB = $this->entity('b');

        $this->writer->transactWrite(new TransactWriteInput()->with(
            new UpdateInput($entityA, ['name' => 'one']),
            new UpdateInput($entityB, ['name' => 'two']),
        ));

        static::assertSame('one', $entityA->getName());
        static::assertSame('two', $entityB->getName());
    }

    /**
     * The update and its existence condition are compiled apart and share one placeholder map, so the
     * request carries both expressions and every name and value either refers to.
     */
    public function testUpdateSendsTheCompiledExpressionBesideItsCondition(): void
    {
        $this->client->expects(static::once())
            ->method('updateItem')
            ->with(static::callback(static function (array $args): bool {
                static::assertSame('SET #name = :u_1_0_name', $args['UpdateExpression'] ?? null);
                static::assertSame('attribute_exists(#autofilledId)', $args['ConditionExpression'] ?? null);
                static::assertSame(['#name' => 'name', '#autofilledId' => 'autofilledId'], $args['ExpressionAttributeNames'] ?? null);
                static::assertEquals([':u_1_0_name' => new AttributeValue(['S' => 'after'])], $args['ExpressionAttributeValues'] ?? null);

                return true;
            }))
            ->willReturn(ResultMockFactory::create(UpdateItemOutput::class));

        $this->writer->update(new UpdateInput(new Key(NormalEntity::class, 'a'), ['name' => 'after']));
    }

    /**
     * An update that writes nothing is refused before any request: a transaction would reject it, and a
     * lone `UpdateItem` without an update expression would pass as a write.
     */
    public function testUpdateThatWritesNothingIsRefusedBeforeAnyRequest(): void
    {
        $this->client->expects(static::never())->method('updateItem');

        $this->expectException(UpdateEmptyException::class);

        $this->writer->update(new UpdateInput(new Key(NormalEntity::class, 'a'), []));
    }

    public function testUpdateOfSeveralThatWritesNothingIsRefusedBeforeTheTransaction(): void
    {
        $this->client->expects(static::never())->method('transactWriteItems');

        $this->expectException(UpdateEmptyException::class);

        $this->writer->transactWrite(new TransactWriteInput()->with(
            new UpdateInput(new Key(NormalEntity::class, 'a'), ['name' => 'one']),
            new UpdateInput(new Key(NormalEntity::class, 'b'), new UpdateExpression()),
        ));
    }

    /**
     * Normalized on the way out like a put, and denormalized on the way back, so the entity keeps its own
     * shape of the field. A real serializer, so the round trip is the real one.
     */
    public function testUpdateOfSeveralWritesTheStoredShapeAndAppliesTheEntitysShape(): void
    {
        $definition = NormalEntity::createDefinition(new PrefixingNormalizer());
        $serializer = new Serializer();
        $registry = new EntityDefinitionRegistry([$definition->getName() => $definition]);

        $writer = $this->createWriter($serializer, $registry);

        $this->client->expects(static::once())
            ->method('transactWriteItems')
            ->with(static::callback(static function (array $args): bool {
                $update = ($args['TransactItems'][0] ?? null)?->getUpdate();
                static::assertNotNull($update);
                static::assertSame(
                    PrefixingNormalizer::PREFIX . 'after',
                    ($update->getExpressionAttributeValues()[':u_1_0_name'] ?? null)?->getS(),
                );

                return true;
            }))
            ->willReturn(ResultMockFactory::create(TransactWriteItemsOutput::class));

        $entityA = $this->entity('a');

        $writer->transactWrite(new TransactWriteInput()->with(
            new UpdateInput($entityA, ['name' => 'after']),
            new UpdateInput($this->entity('b'), ['name' => 'other']),
        ));

        static::assertSame('after', $entityA->getName());
    }

    /**
     * `setIfNotExists()` stores its value as given, so it passes the normalizer like a field that is set, or
     * the row would hold a shape a set never writes.
     */
    public function testUpdateWritesTheStoredShapeOfTheValueAnActionSelects(): void
    {
        $definition = NormalEntity::createDefinition(new PrefixingNormalizer());
        $serializer = new Serializer();
        $registry = new EntityDefinitionRegistry([$definition->getName() => $definition]);

        $writer = $this->createWriter($serializer, $registry);

        $this->client->expects(static::once())
            ->method('updateItem')
            ->with(static::callback(static function (array $args): bool {
                static::assertSame('SET #name = if_not_exists(#name, :u_1_0_name)', $args['UpdateExpression'] ?? null);
                static::assertSame(
                    PrefixingNormalizer::PREFIX . 'first',
                    ($args['ExpressionAttributeValues'][':u_1_0_name'] ?? null)?->getS(),
                );

                return true;
            }))
            ->willReturn(ResultMockFactory::create(UpdateItemOutput::class));

        $writer->update(new UpdateInput(new Key(NormalEntity::class, 'a'), Update::setIfNotExists('name', 'first')));
    }

    /**
     * DynamoDB computes an action's value from the stored item, so the update cannot say what it wrote,
     * even into a whole attribute.
     */
    public function testUpdateOfSeveralReadsBackWhenAnActionComputesTheValue(): void
    {
        $this->client->expects(static::once())
            ->method('transactWriteItems')
            ->willReturn(ResultMockFactory::create(TransactWriteItemsOutput::class));

        // Only the update with an action is read back, and one entity alone takes the single-item read.
        $this->client->expects(static::never())->method('batchGetItem');
        $this->client->expects(static::once())
            ->method('getItem')
            ->with(static::callback(static fn (array $args): bool => ($args['ConsistentRead'] ?? null) === true))
            ->willReturn(ResultMockFactory::create(GetItemOutput::class, ['item' => []]));

        $this->writer->transactWrite(new TransactWriteInput()->with(
            new UpdateInput($this->entity('a'), Update::setIfNotExists('name', 'first')),
            new UpdateInput($this->entity('b'), ['name' => 'two']),
        ));
    }

    /**
     * A stored item takes the update, and the entity the stored item from the update's own response.
     */
    public function testAnUpsertOfAStoredItemSendsOnlyTheUpdate(): void
    {
        $this->client->expects(static::once())
            ->method('updateItem')
            ->with(static::callback(static fn (array $args): bool => ($args['ReturnValues'] ?? null) === ReturnValue::ALL_NEW
                && ($args['ReturnValuesOnConditionCheckFailure'] ?? null) === ReturnValuesOnConditionCheckFailure::ALL_OLD))
            ->willReturn(ResultMockFactory::create(UpdateItemOutput::class, ['attributes' => [
                'autofilledId' => new AttributeValue(['S' => 'a']),
                'required' => new AttributeValue(['S' => 'stored']),
                'name' => new AttributeValue(['S' => 'after']),
            ]]));
        $this->client->expects(static::never())->method('putItem');

        $entity = $this->entity('a')->setName('after');
        static::assertSame(UpsertOutcome::Updated, $this->normalizingWriter(new RecordingNormalizer())->upsert(new UpsertInput($entity, ['name'])));

        static::assertSame('stored', $entity->getRequired());
    }

    /**
     * Where the update finds no item, it fails without one, and the entity is put as a put would put it.
     */
    public function testAnUpsertPutsTheEntityWhereTheUpdateFindsNoItem(): void
    {
        $normalizer = new RecordingNormalizer();

        $this->client->expects(static::once())->method('updateItem')->willThrowException(self::conditionalCheckFailed());
        $this->client->expects(static::once())
            ->method('putItem')
            ->with(static::callback(static fn (array $args): bool => ($args['ConditionExpression'] ?? null) === 'NOT attribute_exists(#autofilledId)'
                && ($args['ReturnValuesOnConditionCheckFailure'] ?? null) === ReturnValuesOnConditionCheckFailure::ALL_OLD))
            ->willReturn(ResultMockFactory::create(PutItemOutput::class));

        static::assertSame(UpsertOutcome::Created, $this->normalizingWriter($normalizer)->upsert(new UpsertInput($this->entity('a'), ['name'])));

        static::assertSame([NormalizerOperation::Put], $this->denormalizedAs($normalizer));
    }

    /**
     * The stored item comes back with the failure, so it is the upsert's own condition that failed on it.
     */
    public function testAnUpsertWhoseConditionFailsOnTheStoredItemIsThrownWithoutAPut(): void
    {
        $failure = self::conditionalCheckFailed(['autofilledId' => ['S' => 'a']]);

        $this->client->expects(static::once())->method('updateItem')->willThrowException($failure);
        $this->client->expects(static::never())->method('putItem');

        try {
            $this->normalizingWriter(new RecordingNormalizer())->upsert(new UpsertInput($this->entity('a'), ['name'], Filter::notExists('name')));
            static::fail('The condition fails on the stored item');
        } catch (ConditionalCheckFailedException $exception) {
            static::assertSame($failure, $exception);
        }
    }

    /**
     * Without an item on the put's failure either, the upsert's own condition failed on the item the put would create.
     */
    public function testAnUpsertWhoseConditionFailsWhereNoItemIsStoredIsThrown(): void
    {
        $failure = self::conditionalCheckFailed();

        $this->client->expects(static::once())->method('updateItem')->willThrowException(self::conditionalCheckFailed());
        $this->client->expects(static::once())->method('putItem')->willThrowException($failure);

        try {
            $this->normalizingWriter(new RecordingNormalizer())->upsert(new UpsertInput($this->entity('a'), ['name'], Filter::exists('name')));
            static::fail('The condition fails where no item is stored');
        } catch (ConditionalCheckFailedException $exception) {
            static::assertSame($failure, $exception);
        }
    }

    /**
     * The put finds an item that another writer created after the update, which now finds that item.
     */
    public function testAnUpsertSendsTheUpdateAgainWhereAnotherWriterCreatedTheItem(): void
    {
        $updates = 0;
        $this->client->expects(static::exactly(2))->method('updateItem')->willReturnCallback(static function () use (&$updates): UpdateItemOutput {
            if (++$updates === 1) {
                throw self::conditionalCheckFailed();
            }

            return ResultMockFactory::create(UpdateItemOutput::class, ['attributes' => [
                'autofilledId' => new AttributeValue(['S' => 'a']),
                'required' => new AttributeValue(['S' => 'req']),
            ]]);
        });
        $this->client->expects(static::once())->method('putItem')->willThrowException(self::conditionalCheckFailed(['autofilledId' => ['S' => 'a']]));

        static::assertSame(UpsertOutcome::Updated, $this->normalizingWriter(new RecordingNormalizer())->upsert(new UpsertInput($this->entity('a'), ['name'])));
    }

    /**
     * Other writers that create and delete the item between both updates and puts let neither be written. That is
     * contention, not the upsert's condition failing, so a caller that reads a failed condition as a refusal never
     * sees one.
     */
    public function testAnUpsertGivesUpAfterTwoRoundsAsContention(): void
    {
        $failure = self::conditionalCheckFailed(['autofilledId' => ['S' => 'a']]);

        $this->client->expects(static::exactly(2))->method('updateItem')->willThrowException(self::conditionalCheckFailed());
        $this->client->expects(static::exactly(2))->method('putItem')->willThrowException($failure);

        try {
            $this->normalizingWriter(new RecordingNormalizer())->upsert(new UpsertInput($this->entity('a'), ['name']));
            static::fail('Other writers created and deleted the item in both rounds');
        } catch (UpsertContentionException $exception) {
            static::assertSame($failure, $exception->getPrevious());
            static::assertSame('normal', $exception->entityDefinition->getName());
        }
    }

    /**
     * The upsert is stored, so the caller still learns which write it was, although the entity fell behind.
     */
    public function testAnUpsertStoredAsAnUpdateThatLeavesItsEntityOutOfSyncSaysSo(): void
    {
        $writer = $this->normalizingWriter(new RecordingNormalizer(denormalize: static fn () => throw new \DomainException('The normalizer refuses the row')));

        $this->client->expects(static::once())
            ->method('updateItem')
            ->willReturn(ResultMockFactory::create(UpdateItemOutput::class, ['attributes' => [
                'autofilledId' => new AttributeValue(['S' => 'a']),
                'required' => new AttributeValue(['S' => 'req']),
            ]]));
        $this->client->expects(static::never())->method('putItem');

        try {
            $writer->upsert(new UpsertInput($this->entity('a'), ['name']));
            static::fail('The normalizer refuses the stored row');
        } catch (EntityOutOfSyncException $exception) {
            static::assertSame(UpsertOutcome::Updated, $exception->upsertOutcome);
            static::assertInstanceOf(DenormalizationException::class, $exception->getPrevious());
        }
    }

    public function testAnUpsertStoredAsAPutThatLeavesItsEntityOutOfSyncSaysSo(): void
    {
        $writer = $this->normalizingWriter(new RecordingNormalizer(denormalize: static fn () => throw new \DomainException('The normalizer refuses the fields')));

        $this->client->expects(static::once())->method('updateItem')->willThrowException(self::conditionalCheckFailed());
        $this->client->expects(static::once())->method('putItem')->willReturn(ResultMockFactory::create(PutItemOutput::class));

        try {
            $writer->upsert(new UpsertInput($this->entity('a'), ['name']));
            static::fail('The normalizer refuses the fields');
        } catch (EntityOutOfSyncException $exception) {
            static::assertSame(UpsertOutcome::Created, $exception->upsertOutcome);
            static::assertInstanceOf(DenormalizationException::class, $exception->getPrevious());
        }
    }

    /**
     * `refresh: Refresh::WithoutReadBack` buys no read: the fields beside an action are applied, the action's target is not.
     */
    public function testUpdateOfSeveralWithoutAReadbackAppliesTheFieldsBesideAnAction(): void
    {
        $this->client->expects(static::once())
            ->method('transactWriteItems')
            ->willReturn(ResultMockFactory::create(TransactWriteItemsOutput::class));

        $this->client->expects(static::never())->method('batchGetItem');

        $entity = $this->entity('a')->setRequiredNullableName('before');

        $this->writer->transactWrite(new TransactWriteInput()->with(
            new UpdateInput($entity, Update::with(Update::set('name', 'one'), Update::setIfNotExists('requiredNullableName', 'after')), refresh: Refresh::WithoutReadBack),
            new UpdateInput($this->entity('b'), ['name' => 'two'], refresh: Refresh::WithoutReadBack),
        ));

        static::assertSame('one', $entity->getName());
        static::assertSame('before', $entity->getRequiredNullableName());
    }

    /**
     * A nested path is the one case the serialized result cannot answer, so it costs a read — strongly
     * consistent, since an eventually consistent one could answer from a replica that has not seen the
     * transaction and backfill a stale row.
     */
    public function testUpdateOfSeveralReadsBackWhenAPathDescendsIntoAnAttribute(): void
    {
        $definition = MapDefinition::create();

        $this->client->expects(static::once())
            ->method('transactWriteItems')
            ->willReturn(ResultMockFactory::create(TransactWriteItemsOutput::class));

        $this->client->expects(static::once())
            ->method('batchGetItem')
            ->with(static::callback(static function (array $args) use ($definition): bool {
                $request = $args['RequestItems'][$definition->getTable()] ?? null;

                return \is_array($request)
                    && $request['ConsistentRead'] === true
                    && \is_array($request['Keys'])
                    && \count($request['Keys']) === 2;
            }))
            ->willReturn(ResultMockFactory::create(BatchGetItemOutput::class, ['responses' => [], 'unprocessedKeys' => []]));

        $this->nestedWriter($definition)->transactWrite(new TransactWriteInput()->with(
            new UpdateInput($this->entity('a'), ['settings.colour' => 'red']),
            new UpdateInput($this->entity('b'), ['settings.colour' => 'blue']),
        ));
    }

    /**
     * `refresh: Refresh::WithoutReadBack` buys no read, so the same nested update leaves that attribute behind instead.
     */
    public function testUpdateOfSeveralWithoutAReadbackNeverReadsBackForANestedPath(): void
    {
        $definition = MapDefinition::create();

        $this->client->expects(static::once())
            ->method('transactWriteItems')
            ->willReturn(ResultMockFactory::create(TransactWriteItemsOutput::class));

        $this->client->expects(static::never())->method('batchGetItem');

        $this->nestedWriter($definition)->transactWrite(new TransactWriteInput()->with(
            new UpdateInput($this->entity('a'), ['settings.colour' => 'red'], refresh: Refresh::WithoutReadBack),
            new UpdateInput($this->entity('b'), ['settings.colour' => 'blue'], refresh: Refresh::WithoutReadBack),
        ));
    }

    public function testUpdateOptedOutOfTheRefreshAsksForNothingBack(): void
    {
        $this->client->expects(static::once())
            ->method('updateItem')
            ->with(static::callback(static fn (array $args): bool => !\array_key_exists('ReturnValues', $args)))
            ->willReturn(ResultMockFactory::create(UpdateItemOutput::class));

        $this->serializer->expects(static::never())->method('deserialize');

        $this->writer->update(new UpdateInput($this->entity('a'), ['name' => 'after'], refresh: Refresh::None));
    }

    public function testUpdateOfSeveralOptedOutOfTheRefreshReadsNothingBack(): void
    {
        $this->client->expects(static::once())
            ->method('transactWriteItems')
            ->willReturn(ResultMockFactory::create(TransactWriteItemsOutput::class));

        $this->client->expects(static::never())->method('batchGetItem');

        $this->writer->transactWrite(new TransactWriteInput()->with(
            new UpdateInput($this->entity('a'), ['name' => 'one'], refresh: Refresh::None),
            new UpdateInput($this->entity('b'), ['name' => 'two'], refresh: Refresh::None),
        ));
    }

    /**
     * Keyed by a {@see Key} there is no entity to fill, so the transaction is not followed by a read.
     */
    public function testUpdateOfSeveralByKeyReadsNothingBack(): void
    {
        $this->client->expects(static::once())
            ->method('transactWriteItems')
            ->willReturn(ResultMockFactory::create(TransactWriteItemsOutput::class));

        $this->client->expects(static::never())->method('batchGetItem');

        $this->writer->transactWrite(new TransactWriteInput()->with(
            new UpdateInput(new Key(NormalEntity::class, 'a'), ['name' => 'one']),
            new UpdateInput(new Key(NormalEntity::class, 'b'), ['name' => 'two']),
        ));
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

        $this->writer->transactWrite(new TransactWriteInput()->with(
            new DeleteInput(new Key(NormalEntity::class, 'a'), Filter::exists('autofilledId')),
            new DeleteInput(new Key(NormalEntity::class, 'b')),
        ));
    }

    public function testTransactWriteRethrowsAfterExhaustingConflictRetries(): void
    {
        $this->client->expects(static::exactly(3))
            ->method('transactWriteItems')
            ->willThrowException($this->transactionCanceledException('TransactionConflict'));

        $this->expectException(TransactionCanceledException::class);

        $this->writer->transactWrite(new TransactWriteInput()->with(
            new DeleteInput(new Key(NormalEntity::class, 'a'), Filter::exists('autofilledId')),
            new DeleteInput(new Key(NormalEntity::class, 'b')),
        ));
    }

    public function testTransactWriteDoesNotRetryANonConflictCancellationReason(): void
    {
        $this->client->expects(static::once())
            ->method('transactWriteItems')
            ->willThrowException($this->transactionCanceledException('ConditionalCheckFailed'));

        $this->expectException(TransactionCanceledException::class);

        $this->writer->transactWrite(new TransactWriteInput()->with(
            new DeleteInput(new Key(NormalEntity::class, 'a'), Filter::exists('autofilledId')),
            new DeleteInput(new Key(NormalEntity::class, 'b')),
        ));
    }

    public function testTransactWriteDoesNotRetryWhenAConflictIsMixedWithAnotherFailureReason(): void
    {
        $this->client->expects(static::once())
            ->method('transactWriteItems')
            ->willThrowException($this->transactionCanceledException('TransactionConflict', 'ConditionalCheckFailed'));

        $this->expectException(TransactionCanceledException::class);

        $this->writer->transactWrite(new TransactWriteInput()->with(
            new DeleteInput(new Key(NormalEntity::class, 'a'), Filter::exists('autofilledId')),
            new DeleteInput(new Key(NormalEntity::class, 'b')),
        ));
    }

    public function testBatchWriteResubmitsUnprocessedItemsUntilNoneRemain(): void
    {
        $table = $this->definition->getTable();
        $unprocessed = [$table => [
            new WriteRequest(['PutRequest' => ['Item' => ['autofilledId' => new AttributeValue(['S' => self::serializedId('b')])]]]),
        ]];

        $withUnprocessed = ResultMockFactory::create(BatchWriteItemOutput::class, ['unprocessedItems' => $unprocessed]);
        $done = ResultMockFactory::create(BatchWriteItemOutput::class, ['unprocessedItems' => []]);

        $entityA = $this->entity('a');
        $entityB = $this->entity('b');

        // An ArrayObject holder, so phpstan does not constant-fold what the callback records.
        /** @var \ArrayObject<int, array<string, list<WriteRequest>>> $requestItemsPerCall */
        $requestItemsPerCall = new \ArrayObject();
        /** @var \ArrayObject<int, list<string>> $idsPerCall */
        $idsPerCall = new \ArrayObject();
        $this->client->expects(static::exactly(2))
            ->method('batchWriteItem')
            ->willReturnCallback(
                /** @param array{RequestItems: array<string, list<WriteRequest>>} $args */
                static function (array $args) use ($requestItemsPerCall, $idsPerCall, $entityA, $entityB, $withUnprocessed, $done): BatchWriteItemOutput {
                    $requestItemsPerCall->append($args['RequestItems']);
                    $idsPerCall->append([$entityA->getAutofilledId(), $entityB->getAutofilledId()]);

                    return \count($requestItemsPerCall) === 1 ? $withUnprocessed : $done;
                },
            );

        $this->writer->batchWrite(new BatchWriteInput()->withPut($entityA, $entityB));

        // First call submits both items keyed by the table; the retry resubmits exactly the unprocessed map
        // (verbatim, not double-nested under the table name again).
        static::assertCount(2, $requestItemsPerCall[0][$table] ?? []);
        static::assertSame($unprocessed, $requestItemsPerCall[1]);

        // Both puts are applied back once the batch is sent, so the leftover goes out beside entities not yet touched
        static::assertSame(['a', 'b'], $idsPerCall[1]);
        static::assertSame(self::serializedId('a'), $entityA->getAutofilledId());
        static::assertSame(self::serializedId('b'), $entityB->getAutofilledId());
    }

    /**
     * DynamoDB refuses a request that names a key twice, after the requests before it were written, and lets the
     * later of two requests win where they are sent apart. So a batch that does is refused before any of it is sent.
     */
    public function testABatchThatPutsAnEntityTwiceIsRefusedBeforeAnyRequest(): void
    {
        $this->client->expects(static::never())->method('batchWriteItem');

        $entities = array_map(fn (int $id): NormalEntity => $this->entity((string) $id), range(1, 25));

        $this->expectException(DuplicateKeyException::class);
        $this->expectExceptionMessage('A batch write or transaction names the same key of item "normal" twice');

        // 25 apart, so the two puts would go out in separate requests
        $this->writer->batchWrite(new BatchWriteInput([...$entities, $entities[0]]));
    }

    public function testABatchThatPutsAndDeletesOneEntityIsRefusedBeforeAnyRequest(): void
    {
        $this->client->expects(static::never())->method('batchWriteItem');

        $this->expectException(DuplicateKeyException::class);

        $this->normalizingWriter(new RecordingNormalizer())->batchWrite(
            new BatchWriteInput([$this->entity('a')], [new Key(NormalEntity::class, 'a')]),
        );
    }

    /**
     * A batch is not atomic, so the entities of the requests that went through are brought up to date although a later
     * request fails, and those of the failed request are not.
     */
    public function testAFailedBatchRequestLeavesTheEntitiesOfTheRequestsBeforeItUpToDate(): void
    {
        /** @var \ArrayObject<int, true> $calls */
        $calls = new \ArrayObject();
        $this->client->expects(static::exactly(2))
            ->method('batchWriteItem')
            ->willReturnCallback(static function () use ($calls): BatchWriteItemOutput {
                $calls->append(true);
                if (\count($calls) === 2) {
                    throw new \RuntimeException('The second request fails');
                }

                return ResultMockFactory::create(BatchWriteItemOutput::class, ['unprocessedItems' => []]);
            });

        $entities = array_map(fn (int $id): NormalEntity => $this->entity((string) $id), range(1, 26));

        try {
            $this->writer->batchWrite(new BatchWriteInput($entities));
            static::fail('The second request fails');
        } catch (\RuntimeException) {
        }

        static::assertSame(self::serializedId('25'), $entities[24]->getAutofilledId());
        static::assertSame('26', $entities[25]->getAutofilledId());
    }

    /**
     * Past 100 operations, each transaction is atomic on its own, so the entities of one that went through are brought
     * up to date although a later one fails.
     */
    public function testAFailedTransactionLeavesTheEntitiesOfTheTransactionsBeforeItUpToDate(): void
    {
        $cancellation = $this->transactionCanceledException('ConditionalCheckFailed');

        /** @var \ArrayObject<int, true> $calls */
        $calls = new \ArrayObject();
        $this->client->expects(static::exactly(2))
            ->method('transactWriteItems')
            ->willReturnCallback(static function () use ($calls, $cancellation): TransactWriteItemsOutput {
                $calls->append(true);
                if (\count($calls) === 2) {
                    throw $cancellation;
                }

                return ResultMockFactory::create(TransactWriteItemsOutput::class);
            });

        $entities = array_map(fn (int $id): NormalEntity => $this->entity((string) $id), range(1, 101));

        try {
            $this->writer->transactWrite(new TransactWriteInput(...array_map(static fn (NormalEntity $entity): PutInput => new PutInput($entity), $entities)));
            static::fail('The second transaction is cancelled');
        } catch (TransactionCanceledException) {
        }

        static::assertSame(self::serializedId('100'), $entities[99]->getAutofilledId());
        static::assertSame('101', $entities[100]->getAutofilledId());
    }

    /**
     * DynamoDB refuses a transaction that writes an item twice, and past 100 operations, whether two of them land in one
     * transaction depends on where they stand. So a transaction that names a key twice is refused before any of it is sent.
     */
    public function testATransactionThatNamesAKeyTwiceIsRefusedBeforeAnyRequest(): void
    {
        $this->client->expects(static::never())->method('transactWriteItems');

        $deletes = array_map(static fn (int $id): DeleteInput => new DeleteInput(new Key(NormalEntity::class, (string) $id)), range(1, 100));

        $this->expectException(DuplicateKeyException::class);

        // 100 apart, so the two would go out in separate transactions
        $this->writer->transactWrite(new TransactWriteInput(...[...$deletes, new UpdateInput(new Key(NormalEntity::class, '1'), ['name' => 'one'])]));
    }

    /**
     * DynamoDB applies a transaction once per token, however often AsyncAws sends it. A cancelled one applied nothing,
     * so the next attempt is a transaction of its own, with a token of its own.
     */
    public function testEachAttemptAtATransactionCarriesATokenOfItsOwn(): void
    {
        $conflict = $this->transactionCanceledException('TransactionConflict');

        /** @var \ArrayObject<int, mixed> $tokens */
        $tokens = new \ArrayObject();
        $this->client->expects(static::exactly(2))
            ->method('transactWriteItems')
            ->willReturnCallback(static function (array $args) use ($tokens, $conflict): TransactWriteItemsOutput {
                $tokens->append($args['ClientRequestToken'] ?? null);
                if (\count($tokens) === 1) {
                    throw $conflict;
                }

                return ResultMockFactory::create(TransactWriteItemsOutput::class);
            });

        $this->writer->transactWrite(new TransactWriteInput(new DeleteInput(new Key(NormalEntity::class, 'a'))));

        static::assertIsString($tokens[0]);
        static::assertIsString($tokens[1]);
        // DynamoDB takes a token of up to 36 characters
        static::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $tokens[0]);
        static::assertNotSame($tokens[0], $tokens[1]);
    }

    public function testTransactWriteRetriesAThrottledTransaction(): void
    {
        $throttled = $this->transactionCanceledException('None', 'ThrottlingError');

        /** @var \ArrayObject<int, true> $calls */
        $calls = new \ArrayObject();
        $this->client->expects(static::exactly(2))
            ->method('transactWriteItems')
            ->willReturnCallback(static function () use ($calls, $throttled): TransactWriteItemsOutput {
                $calls->append(true);
                if (\count($calls) === 1) {
                    throw $throttled;
                }

                return ResultMockFactory::create(TransactWriteItemsOutput::class);
            });

        $this->writer->transactWrite(new TransactWriteInput(
            new DeleteInput(new Key(NormalEntity::class, 'a')),
            new DeleteInput(new Key(NormalEntity::class, 'b')),
        ));
    }

    /**
     * The update is stored before its entity takes the row, so a row that does not deserialize is caught as before, as a
     * {@see DeserializationException}, and told apart from a write that failed.
     */
    public function testAnUpdateStoredWithARowThatDoesNotDeserializeLeavesItsEntityOutOfSync(): void
    {
        $this->client->expects(static::once())
            ->method('updateItem')
            ->willReturn(ResultMockFactory::create(UpdateItemOutput::class, ['attributes' => ['autofilledId' => new AttributeValue(['S' => 'a'])]]));

        $missing = $this->missingRequiredField();
        $this->serializer->method('deserialize')->willThrowException($missing);

        try {
            $this->writer->update(new UpdateInput($this->entity('a'), ['name' => 'after']));
            static::fail('The stored row does not deserialize');
        } catch (DeserializationException $exception) {
            static::assertInstanceOf(EntityOutOfSyncException::class, $exception);
            static::assertSame($missing, $exception->getPrevious());
        }
    }

    /**
     * A normalizer may throw what it likes, and the serializer makes it a DAL failure, so once the update is stored,
     * that is no failed write either.
     */
    public function testAnUpdateStoredWithARowItsNormalizerRefusesLeavesItsEntityOutOfSync(): void
    {
        $refused = new \DomainException('The normalizer refuses the row');
        $writer = $this->normalizingWriter(new RecordingNormalizer(denormalize: static fn () => throw $refused));

        $this->client->expects(static::once())
            ->method('updateItem')
            ->willReturn(ResultMockFactory::create(UpdateItemOutput::class, ['attributes' => [
                'autofilledId' => new AttributeValue(['S' => 'a']),
                'required' => new AttributeValue(['S' => 'req']),
            ]]));

        try {
            $writer->update(new UpdateInput($this->entity('a'), ['name' => 'after']));
            static::fail('The normalizer refuses the stored row');
        } catch (EntityOutOfSyncException $exception) {
            static::assertInstanceOf(DenormalizationException::class, $exception->getPrevious());
            static::assertSame($refused, $exception->getPrevious()->getPrevious());
        }
    }

    /**
     * Neither is a put or a transaction whose write-back fails in the normalizer.
     */
    public function testAPutStoredWithFieldsItsNormalizerRefusesLeavesItsEntityOutOfSync(): void
    {
        $refused = new \DomainException('The normalizer refuses the fields');
        $writer = $this->normalizingWriter(new RecordingNormalizer(denormalize: static fn () => throw $refused));

        $this->client->expects(static::once())
            ->method('putItem')
            ->willReturn(ResultMockFactory::create(PutItemOutput::class));

        try {
            $writer->put(new PutInput($this->entity('a')));
            static::fail('The normalizer refuses the fields');
        } catch (EntityOutOfSyncException $exception) {
            static::assertInstanceOf(DenormalizationException::class, $exception->getPrevious());
            static::assertSame($refused, $exception->getPrevious()->getPrevious());
        }
    }

    /**
     * After a failed batch, the one the caller has to handle is the failed request, whatever the write-back throws.
     */
    public function testAFailedBatchRequestIsThrownRatherThanAWriteBackTheNormalizerRefuses(): void
    {
        $writer = $this->normalizingWriter(new RecordingNormalizer(denormalize: static fn () => throw new \DomainException('The normalizer refuses the fields')));
        $failure = new \RuntimeException('The second request fails');

        /** @var \ArrayObject<int, true> $calls */
        $calls = new \ArrayObject();
        $this->client->expects(static::exactly(2))
            ->method('batchWriteItem')
            ->willReturnCallback(static function () use ($calls, $failure): BatchWriteItemOutput {
                $calls->append(true);
                if (\count($calls) === 2) {
                    throw $failure;
                }

                return ResultMockFactory::create(BatchWriteItemOutput::class, ['unprocessedItems' => []]);
            });

        try {
            $writer->batchWrite(new BatchWriteInput(array_map(fn (int $id): NormalEntity => $this->entity((string) $id), range(1, 26))));
            static::fail('The second request fails');
        } catch (\RuntimeException $exception) {
            static::assertSame($failure, $exception);
        }
    }

    /**
     * The entities are brought up to date once every transaction is sent, so one that cannot be read back keeps none of
     * them from being sent, and the others still take what they wrote.
     */
    public function testAnEntityThatCannotBeReadBackKeepsNoTransactionFromBeingSent(): void
    {
        $this->client->expects(static::exactly(2))
            ->method('transactWriteItems')
            ->willReturnCallback(static fn (): TransactWriteItemsOutput => ResultMockFactory::create(TransactWriteItemsOutput::class));

        $missing = $this->failingReadBack();

        $puts = array_map(fn (int $id): PutInput => new PutInput($this->entity((string) $id)), range(1, 100));

        try {
            $this->writer->transactWrite(new TransactWriteInput(...[new UpdateInput($this->entity('a'), Update::setIfNotExists('name', 'first')), ...$puts]));
            static::fail('The updated entity cannot be read back');
        } catch (EntityOutOfSyncException $exception) {
            static::assertSame($missing, $exception->getPrevious());
        }

        static::assertSame(self::serializedId('100'), $puts[99]->entity->getAutofilledId());
    }

    /**
     * After a failed transaction, the entities of the ones before it are brought up to date as far as they can be. The
     * caller has to handle the failed transaction, so a failure to bring one up to date does not take its place.
     */
    public function testAFailedTransactionIsThrownRatherThanAnEntityThatCannotBeReadBack(): void
    {
        $cancellation = $this->transactionCanceledException('ConditionalCheckFailed');

        /** @var \ArrayObject<int, true> $calls */
        $calls = new \ArrayObject();
        $this->client->expects(static::exactly(2))
            ->method('transactWriteItems')
            ->willReturnCallback(static function () use ($calls, $cancellation): TransactWriteItemsOutput {
                $calls->append(true);
                if (\count($calls) === 2) {
                    throw $cancellation;
                }

                return ResultMockFactory::create(TransactWriteItemsOutput::class);
            });

        $this->failingReadBack();

        $puts = array_map(fn (int $id): PutInput => new PutInput($this->entity((string) $id)), range(1, 100));

        try {
            $this->writer->transactWrite(new TransactWriteInput(...[new UpdateInput($this->entity('a'), Update::setIfNotExists('name', 'first')), ...$puts]));
            static::fail('The second transaction is cancelled');
        } catch (TransactionCanceledException $exception) {
            static::assertSame($cancellation, $exception);
        }

        // The first transaction's puts are brought up to date, and those of the one cancelled are not
        static::assertSame(self::serializedId('99'), $puts[98]->entity->getAutofilledId());
        static::assertSame('100', $puts[99]->entity->getAutofilledId());
    }

    /**
     * An entity read back takes a `GetItem` when it is the only one, and its row does not deserialize.
     */
    private function failingReadBack(): FieldMissingDeserializedValueException
    {
        $this->client->expects(static::once())
            ->method('getItem')
            ->with(static::callback(static fn (array $args): bool => ($args['ConsistentRead'] ?? null) === true))
            ->willReturn(ResultMockFactory::create(GetItemOutput::class, ['item' => ['autofilledId' => new AttributeValue(['S' => 'a'])]]));

        $missing = $this->missingRequiredField();
        $this->serializer->method('deserialize')->willThrowException($missing);

        return $missing;
    }

    private function missingRequiredField(): FieldMissingDeserializedValueException
    {
        $required = $this->definition->getFieldDefinition('required');
        static::assertNotNull($required);

        return new FieldMissingDeserializedValueException($required);
    }

    /**
     * A writer over {@see MapDefinition}, the only fixture whose paths descend into an attribute.
     *
     * @param EntityDefinition<NormalEntity> $definition
     */
    private function nestedWriter(EntityDefinition $definition): WriterClient
    {
        $serializer = $this->createMock(Serializer::class);
        $serializer->method('normalize')->willReturnArgument(1);
        $serializer->method('serializeKey')
            ->willReturnCallback(static fn (EntityDefinition $d, mixed $key): SerializedKeyResult => SerializedKeyResult::fromItem($d, [
                'settings' => new AttributeValue(['S' => spl_object_hash((object) $key)]),
            ]));
        $serializer->method('denormalize')->willReturnArgument(1);
        $serializer->method('assign')->willReturnCallback(static fn (EntityDefinition $d, AbstractEntity $entity, array $fields): AbstractEntity => $entity->setVars($fields));

        $registry = new EntityDefinitionRegistry([$definition->getName() => $definition]);

        return $this->createWriter($serializer, $registry);
    }

    /**
     * A writer over two tables, for requests that span entity classes.
     */
    private function twoTableWriter(): WriterClient
    {
        $other = OtherEntity::createDefinition();
        $registry = new EntityDefinitionRegistry([$this->definition->getName() => $this->definition, $other->getName() => $other]);

        return $this->createWriter($this->serializer, $registry);
    }

    /**
     * A writer on a real serializer, so the normalizer runs as it does outside the test.
     */
    private function normalizingWriter(AbstractNormalizer $normalizer): WriterClient
    {
        $definition = NormalEntity::createDefinition($normalizer);
        $serializer = new Serializer();
        $registry = new EntityDefinitionRegistry([$definition->getName() => $definition]);

        return $this->createWriter($serializer, $registry);
    }

    /**
     * A writer over the mocked client, wired as `config/services.php` wires it.
     */
    private function createWriter(Serializer $serializer, EntityDefinitionRegistry $registry): WriterClient
    {
        return new WriterClient(
            $this->client,
            $serializer,
            new WriteRequestFactory($serializer, new FilterCompiler(), new UpdateCompiler($serializer), $registry),
            new ReaderClient($this->client, $serializer, new ReadRequestFactory($serializer, new FilterCompiler(), $registry)),
        );
    }

    /**
     * @return list<NormalizerOperation>
     */
    private function denormalizedAs(RecordingNormalizer $normalizer): array
    {
        $operations = [];
        foreach ($normalizer->calls as [$side, $operation]) {
            if ($side === 'denormalize') {
                $operations[] = $operation;
            }
        }

        return $operations;
    }

    private function entity(string $id): NormalEntity
    {
        return new NormalEntity()->setAutofilledId($id)->setRequired('req');
    }

    /**
     * A put of the entity as if its normalizer changed its key, so that a test sees the put applied back onto it,
     * while every entity keeps a key of its own.
     */
    private function serializedResult(NormalizerOperation $operation, mixed $entity): SerializedResult
    {
        static::assertInstanceOf(NormalEntity::class, $entity);
        $id = self::serializedId($entity->getAutofilledId());
        $idPath = FieldPath::parse($this->definition, 'autofilledId');

        return new SerializedResult(
            ['autofilledId' => new SerializedFieldResult($idPath, new AttributeValue(['S' => $id]))],
            ['autofilledId' => $id],
            $operation,
        );
    }

    private static function serializedId(string $id): string
    {
        return "{$id}-serialized";
    }

    /**
     * @param array<string, array<string, string>> $item - the stored item DynamoDB returns with the failure, in its JSON shape; none where no item is stored
     */
    private static function conditionalCheckFailed(array $item = []): ConditionalCheckFailedException
    {
        $body = ['message' => 'The conditional request failed', ...($item !== [] ? ['Item' => $item] : [])];

        return new ConditionalCheckFailedException(
            new SimpleMockedResponse(json_encode($body) ?: '{}', ['content-type' => 'application/x-amz-json-1.0'], 400),
            new AwsError('ConditionalCheckFailedException', 'The conditional request failed', 'Client', null),
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
