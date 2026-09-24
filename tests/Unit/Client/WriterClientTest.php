<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Client;

use Shopware\DynamodbDalBundle\Client\Index;
use Shopware\DynamodbDalBundle\Client\Input\DeleteInput;
use Shopware\DynamodbDalBundle\Client\Input\PutInput;
use Shopware\DynamodbDalBundle\Client\Input\UpdateInput;
use Shopware\DynamodbDalBundle\Client\WriterClient;
use Shopware\DynamodbDalBundle\Expression\ExpressionCompiler;
use Shopware\DynamodbDalBundle\Expression\Filter;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\EntityDefinitionRegistry;
use Shopware\DynamodbDalBundle\Definition\FieldPath;
use Shopware\DynamodbDalBundle\Client\ReaderClient;
use Shopware\DynamodbDalBundle\Exception\UnknownEntityDefinitionException;
use Shopware\DynamodbDalBundle\Serializer\AbstractNormalizer;
use Shopware\DynamodbDalBundle\Serializer\NormalizerOperation;
use Shopware\DynamodbDalBundle\Exception\UpdateEmptyException;
use Shopware\DynamodbDalBundle\Expression\Update;
use Shopware\DynamodbDalBundle\Expression\Update\UpdateExpression;
use Shopware\DynamodbDalBundle\Serializer\SerializedFieldResult;
use Shopware\DynamodbDalBundle\Serializer\SerializedResult;
use Shopware\DynamodbDalBundle\Serializer\Serializer;
use Shopware\DynamodbDalBundle\Tests\Unit\Definition\Fixtures\MapDefinition;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\NormalEntity;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\OtherEntity;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\PrefixingNormalizer;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\RecordingNormalizer;
use AsyncAws\Core\Test\ResultMockFactory;
use AsyncAws\DynamoDb\DynamoDbClient;
use AsyncAws\DynamoDb\Enum\ReturnValue;
use AsyncAws\DynamoDb\Exception\TransactionCanceledException;
use AsyncAws\DynamoDb\Result\BatchGetItemOutput;
use AsyncAws\DynamoDb\Result\BatchWriteItemOutput;
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

        $this->serializer->method('serialize')->willReturnCallback(
            fn (EntityDefinition $definition, mixed $fields, NormalizerOperation $operation): SerializedResult => $this->serializedResult($operation),
        );
        $this->serializer->method('serializeKey')
            ->willReturn(['autofilledId' => new AttributeValue(['S' => self::SERIALIZED_ID])]);
        // The definitions here carry no shape-changing normalizer, so (de)normalizing is the identity —
        // stubbed rather than mocked away, since an update and the write-back run through it.
        $this->serializer->method('normalize')->willReturnArgument(1);
        $this->serializer->method('denormalize')->willReturnArgument(1);

        $registry = new EntityDefinitionRegistry([$this->definition->getName() => $this->definition]);
        $this->writer = new WriterClient(
            $this->client,
            $this->serializer,
            new ExpressionCompiler($this->serializer),
            $registry,
            new ReaderClient($this->client, $this->serializer, new ExpressionCompiler($this->serializer), $registry),
        );
    }

    public function testPutWithoutInputsIsANoOp(): void
    {
        $this->client->expects(static::never())->method('putItem');
        $this->client->expects(static::never())->method('batchWriteItem');
        $this->client->expects(static::never())->method('transactWriteItems');

        $this->writer->put(NormalEntity::class);
    }

    public function testUpdateWithoutInputsIsANoOp(): void
    {
        $this->client->expects(static::never())->method('updateItem');
        $this->client->expects(static::never())->method('transactWriteItems');

        $this->writer->update(NormalEntity::class);
    }

    public function testDeleteWithoutInputsIsANoOp(): void
    {
        $this->client->expects(static::never())->method('deleteItem');
        $this->client->expects(static::never())->method('batchWriteItem');
        $this->client->expects(static::never())->method('transactWriteItems');

        $this->writer->delete(NormalEntity::class);
    }

    public function testPutRejectsAnUnregisteredClass(): void
    {
        $this->client->expects(static::never())->method('putItem');

        $this->expectException(UnknownEntityDefinitionException::class);
        $this->writer->put(OtherEntity::class, new PutInput(new OtherEntity()->setOtherId('o')));
    }

    public function testUpdateRejectsAnUnregisteredClass(): void
    {
        $this->client->expects(static::never())->method('updateItem');

        $this->expectException(UnknownEntityDefinitionException::class);
        $this->writer->update(OtherEntity::class, new UpdateInput(new Index('o'), ['otherId' => 'o']));
    }

    public function testDeleteRejectsAnUnregisteredClass(): void
    {
        $this->client->expects(static::never())->method('deleteItem');

        $this->expectException(UnknownEntityDefinitionException::class);
        $this->writer->delete(OtherEntity::class, DeleteInput::fromIndex('o'));
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

        $writer->put(NormalEntity::class, new PutInput($entity));

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

        $writer->update(
            NormalEntity::class,
            new UpdateInput($this->entity('a'), ['name' => 'one']),
            new UpdateInput($this->entity('b'), ['name' => 'two']),
        );

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

        $this->normalizingWriter($normalizer)->put(NormalEntity::class, new PutInput($this->entity('a')));

        static::assertSame([NormalizerOperation::Put], $this->denormalizedAs($normalizer));
    }

    public function testABatchOfPutsIsDenormalizedAsThePutsThatWroteThem(): void
    {
        $normalizer = new RecordingNormalizer();

        $this->client->expects(static::once())
            ->method('batchWriteItem')
            ->willReturn(ResultMockFactory::create(BatchWriteItemOutput::class, ['unprocessedItems' => []]));

        $this->normalizingWriter($normalizer)->put(
            NormalEntity::class,
            new PutInput($this->entity('a')),
            new PutInput($this->entity('b')),
        );

        static::assertSame([NormalizerOperation::Put, NormalizerOperation::Put], $this->denormalizedAs($normalizer));
    }

    public function testATransactionOfPutsIsDenormalizedAsThePutsThatWroteThem(): void
    {
        $normalizer = new RecordingNormalizer();

        $this->client->expects(static::once())
            ->method('transactWriteItems')
            ->willReturn(ResultMockFactory::create(TransactWriteItemsOutput::class));

        $this->normalizingWriter($normalizer)->put(
            NormalEntity::class,
            new PutInput($this->entity('a'), Filter::notExists('autofilledId')),
            new PutInput($this->entity('b')),
        );

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
        $this->normalizingWriter($normalizer)->update(NormalEntity::class, new UpdateInput($entity, ['name' => 'after']));

        static::assertSame([NormalizerOperation::Read], $this->denormalizedAs($normalizer));
        static::assertSame('after', $entity->getName());
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
            NormalEntity::class,
            new PutInput($entityA, Filter::notExists('autofilledId')),
            new PutInput($entityB),
        );

        static::assertSame(self::SERIALIZED_ID, $entityA->getAutofilledId());
        static::assertSame(self::SERIALIZED_ID, $entityB->getAutofilledId());
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

        $this->writer->update(NormalEntity::class, new UpdateInput($entity, ['name' => 'after']));
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

        $this->writer->update(NormalEntity::class, new UpdateInput(new Index('a'), ['name' => 'after']));
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

        $this->writer->update(
            NormalEntity::class,
            new UpdateInput($entityA, ['name' => 'one']),
            new UpdateInput($entityB, ['name' => 'two']),
        );

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
                static::assertSame('SET #name = :ex_1_0_name', $args['UpdateExpression'] ?? null);
                static::assertSame('attribute_exists(#autofilledId)', $args['ConditionExpression'] ?? null);
                static::assertSame(['#name' => 'name', '#autofilledId' => 'autofilledId'], $args['ExpressionAttributeNames'] ?? null);
                static::assertEquals([':ex_1_0_name' => new AttributeValue(['S' => 'after'])], $args['ExpressionAttributeValues'] ?? null);

                return true;
            }))
            ->willReturn(ResultMockFactory::create(UpdateItemOutput::class));

        $this->writer->update(NormalEntity::class, new UpdateInput(new Index('a'), ['name' => 'after']));
    }

    /**
     * An update that writes nothing is refused before any request: a transaction would reject it, and a
     * lone `UpdateItem` without an update expression would pass as a write.
     */
    public function testUpdateThatWritesNothingIsRefusedBeforeAnyRequest(): void
    {
        $this->client->expects(static::never())->method('updateItem');

        $this->expectException(UpdateEmptyException::class);

        $this->writer->update(NormalEntity::class, new UpdateInput(new Index('a'), []));
    }

    public function testUpdateOfSeveralThatWritesNothingIsRefusedBeforeTheTransaction(): void
    {
        $this->client->expects(static::never())->method('transactWriteItems');

        $this->expectException(UpdateEmptyException::class);

        $this->writer->update(
            NormalEntity::class,
            new UpdateInput(new Index('a'), ['name' => 'one']),
            new UpdateInput(new Index('b'), new UpdateExpression()),
        );
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

        $writer = new WriterClient(
            $this->client,
            $serializer,
            new ExpressionCompiler($serializer),
            $registry,
            new ReaderClient($this->client, $serializer, new ExpressionCompiler($serializer), $registry),
        );

        $this->client->expects(static::once())
            ->method('transactWriteItems')
            ->with(static::callback(static function (array $args): bool {
                $update = ($args['TransactItems'][0] ?? null)?->getUpdate();
                static::assertNotNull($update);
                static::assertSame(
                    PrefixingNormalizer::PREFIX . 'after',
                    ($update->getExpressionAttributeValues()[':ex_1_0_name'] ?? null)?->getS(),
                );

                return true;
            }))
            ->willReturn(ResultMockFactory::create(TransactWriteItemsOutput::class));

        $entityA = $this->entity('a');

        $writer->update(
            NormalEntity::class,
            new UpdateInput($entityA, ['name' => 'after']),
            new UpdateInput($this->entity('b'), ['name' => 'other']),
        );

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

        $writer = new WriterClient(
            $this->client,
            $serializer,
            new ExpressionCompiler($serializer),
            $registry,
            new ReaderClient($this->client, $serializer, new ExpressionCompiler($serializer), $registry),
        );

        $this->client->expects(static::once())
            ->method('updateItem')
            ->with(static::callback(static function (array $args): bool {
                static::assertSame('SET #name = if_not_exists(#name, :ex_1_0_name)', $args['UpdateExpression'] ?? null);
                static::assertSame(
                    PrefixingNormalizer::PREFIX . 'first',
                    ($args['ExpressionAttributeValues'][':ex_1_0_name'] ?? null)?->getS(),
                );

                return true;
            }))
            ->willReturn(ResultMockFactory::create(UpdateItemOutput::class));

        $writer->update(NormalEntity::class, new UpdateInput(new Index('a'), Update::setIfNotExists('name', 'first')));
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

        $this->writer->update(
            NormalEntity::class,
            new UpdateInput($this->entity('a'), Update::setIfNotExists('name', 'first')),
            new UpdateInput($this->entity('b'), ['name' => 'two']),
        );
    }

    /**
     * `refresh: null` buys no read: the fields beside an action are applied, the action's target is not.
     */
    public function testUpdateOfSeveralWithoutAReadbackAppliesTheFieldsBesideAnAction(): void
    {
        $this->client->expects(static::once())
            ->method('transactWriteItems')
            ->willReturn(ResultMockFactory::create(TransactWriteItemsOutput::class));

        $this->client->expects(static::never())->method('batchGetItem');

        $entity = $this->entity('a')->setRequiredNullableName('before');

        $this->writer->update(
            NormalEntity::class,
            new UpdateInput($entity, Update::with(Update::set('name', 'one'), Update::setIfNotExists('requiredNullableName', 'after')), refresh: null),
            new UpdateInput($this->entity('b'), ['name' => 'two'], refresh: null),
        );

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

        $this->nestedWriter($definition)->update(
            NormalEntity::class,
            new UpdateInput($this->entity('a'), ['settings.colour' => 'red']),
            new UpdateInput($this->entity('b'), ['settings.colour' => 'blue']),
        );
    }

    /**
     * `refresh: null` buys no read, so the same nested update leaves that attribute behind instead.
     */
    public function testUpdateOfSeveralWithoutAReadbackNeverReadsBackForANestedPath(): void
    {
        $definition = MapDefinition::create();

        $this->client->expects(static::once())
            ->method('transactWriteItems')
            ->willReturn(ResultMockFactory::create(TransactWriteItemsOutput::class));

        $this->client->expects(static::never())->method('batchGetItem');

        $this->nestedWriter($definition)->update(
            NormalEntity::class,
            new UpdateInput($this->entity('a'), ['settings.colour' => 'red'], refresh: null),
            new UpdateInput($this->entity('b'), ['settings.colour' => 'blue'], refresh: null),
        );
    }

    public function testUpdateOptedOutOfTheRefreshAsksForNothingBack(): void
    {
        $this->client->expects(static::once())
            ->method('updateItem')
            ->with(static::callback(static fn (array $args): bool => !\array_key_exists('ReturnValues', $args)))
            ->willReturn(ResultMockFactory::create(UpdateItemOutput::class));

        $this->serializer->expects(static::never())->method('deserialize');

        $this->writer->update(NormalEntity::class, new UpdateInput($this->entity('a'), ['name' => 'after'], refresh: false));
    }

    public function testUpdateOfSeveralOptedOutOfTheRefreshReadsNothingBack(): void
    {
        $this->client->expects(static::once())
            ->method('transactWriteItems')
            ->willReturn(ResultMockFactory::create(TransactWriteItemsOutput::class));

        $this->client->expects(static::never())->method('batchGetItem');

        $this->writer->update(
            NormalEntity::class,
            new UpdateInput($this->entity('a'), ['name' => 'one'], refresh: false),
            new UpdateInput($this->entity('b'), ['name' => 'two'], refresh: false),
        );
    }

    /**
     * Keyed by an index there is no entity to fill, so the transaction is not followed by a read.
     */
    public function testUpdateOfSeveralByIndexReadsNothingBack(): void
    {
        $this->client->expects(static::once())
            ->method('transactWriteItems')
            ->willReturn(ResultMockFactory::create(TransactWriteItemsOutput::class));

        $this->client->expects(static::never())->method('batchGetItem');

        $this->writer->update(
            NormalEntity::class,
            new UpdateInput(new Index('a'), ['name' => 'one']),
            new UpdateInput(new Index('b'), ['name' => 'two']),
        );
    }

    public function testDeleteWithAConditionOnSeveralInputsFallsBackToTransactWriteItems(): void
    {
        $this->client->expects(static::never())->method('batchWriteItem');
        $this->client->expects(static::once())
            ->method('transactWriteItems')
            ->with(static::callback(static fn (array $args): bool => \count($args['TransactItems'] ?? []) === 2))
            ->willReturn(ResultMockFactory::create(TransactWriteItemsOutput::class));

        $this->writer->delete(
            NormalEntity::class,
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
            NormalEntity::class,
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
            NormalEntity::class,
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
            NormalEntity::class,
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
            NormalEntity::class,
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

        // An ArrayObject holder, so phpstan does not constant-fold what the callback records.
        /** @var \ArrayObject<int, array<string, list<WriteRequest>>> $requestItemsPerCall */
        $requestItemsPerCall = new \ArrayObject();
        $this->client->expects(static::exactly(2))
            ->method('batchWriteItem')
            ->willReturnCallback(
                /** @param array{RequestItems: array<string, list<WriteRequest>>} $args */
                static function (array $args) use ($requestItemsPerCall, $withUnprocessed, $done): BatchWriteItemOutput {
                    $requestItemsPerCall->append($args['RequestItems']);

                    return \count($requestItemsPerCall) === 1 ? $withUnprocessed : $done;
                },
            );

        $entityA = $this->entity('a');
        $entityB = $this->entity('b');

        $this->writer->put(NormalEntity::class, new PutInput($entityA), new PutInput($entityB));

        // First call submits both items keyed by the table; the retry resubmits exactly the unprocessed map
        // (verbatim, not double-nested under the table name again).
        static::assertCount(2, $requestItemsPerCall[0][$table] ?? []);
        static::assertSame($unprocessed, $requestItemsPerCall[1]);

        static::assertSame(self::SERIALIZED_ID, $entityA->getAutofilledId());
        static::assertSame(self::SERIALIZED_ID, $entityB->getAutofilledId());
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
            ->willReturnCallback(static fn (EntityDefinition $d, mixed $key): array => [
                'settings' => new AttributeValue(['S' => spl_object_hash((object) $key)]),
            ]);
        $serializer->method('denormalize')->willReturnArgument(1);

        $registry = new EntityDefinitionRegistry([$definition->getName() => $definition]);

        return new WriterClient(
            $this->client,
            $serializer,
            new ExpressionCompiler($serializer),
            $registry,
            new ReaderClient($this->client, $serializer, new ExpressionCompiler($serializer), $registry),
        );
    }

    /**
     * A writer on a real serializer, so the normalizer runs as it does outside the test.
     */
    private function normalizingWriter(AbstractNormalizer $normalizer): WriterClient
    {
        $definition = NormalEntity::createDefinition($normalizer);
        $serializer = new Serializer();
        $registry = new EntityDefinitionRegistry([$definition->getName() => $definition]);

        return new WriterClient(
            $this->client,
            $serializer,
            new ExpressionCompiler($serializer),
            $registry,
            new ReaderClient($this->client, $serializer, new ExpressionCompiler($serializer), $registry),
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

    private function serializedResult(NormalizerOperation $operation): SerializedResult
    {
        $idPath = FieldPath::tryParse($this->definition, 'autofilledId');
        static::assertNotNull($idPath);

        return new SerializedResult(
            $this->definition,
            ['autofilledId' => new SerializedFieldResult($idPath, new AttributeValue(['S' => self::SERIALIZED_ID]))],
            ['autofilledId' => self::SERIALIZED_ID],
            $operation,
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
