<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Integration\DynamoDb;

use AsyncAws\DynamoDb\Exception\ConditionalCheckFailedException;
use AsyncAws\DynamoDb\Exception\TransactionCanceledException;
use AsyncAws\DynamoDb\ValueObject\CancellationReason;
use PHPUnit\Framework\Attributes\CoversClass;
use Shopware\DynamodbDalBundle\Client\Key;
use Shopware\DynamodbDalBundle\Client\Input\BatchWriteInput;
use Shopware\DynamodbDalBundle\Client\Input\DeleteInput;
use Shopware\DynamodbDalBundle\Client\Input\GetInput;
use Shopware\DynamodbDalBundle\Client\Input\PutInput;
use Shopware\DynamodbDalBundle\Client\Input\Refresh;
use Shopware\DynamodbDalBundle\Client\Input\ScanInput;
use Shopware\DynamodbDalBundle\Client\Input\TransactWriteInput;
use Shopware\DynamodbDalBundle\Client\Input\UpdateInput;
use Shopware\DynamodbDalBundle\Client\WriterClient;
use Shopware\DynamodbDalBundle\Expression\Filter;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\Entity\ArchiveEntity;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\Entity\NormalizedEntity;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\Entity\NormalizedEntityNormalizer;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\Entity\RecordEntity;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\Entity\RecordStatus;

/**
 * The write engine against real tables: which API a given set of inputs takes, what a condition
 * expression does when it holds and when it does not, and what lands back on the entity afterwards.
 */
#[CoversClass(WriterClient::class)]
class WriterClientTest extends DynamoDbTestCase
{
    public function testPutSingleWritesTheItem(): void
    {
        $this->writer()->put(new PutInput(RecordEntity::create(self::TENANT, 'a', name: 'written')));

        static::assertSame('written', $this->read('a')?->name);
    }

    public function testPutBackfillsThePassedEntity(): void
    {
        $entity = NormalizedEntity::create(self::TENANT, 'invoice');

        $this->writer()->put(new PutInput($entity));

        static::assertSame(self::TENANT . '#invoice', $entity->pk);
        static::assertTrue(isset($entity->id));
        static::assertSame(1_700_000_000, $entity->createdAt->getTimestamp());
    }

    public function testPutBacksFillsANormalizationGeneratedKeyMatchingTheStoredRow(): void
    {
        $entity = NormalizedEntity::create(self::TENANT, 'invoice');

        $this->writer()->put(new PutInput($entity));

        $read = $this->client()->get(new GetInput([new Key(NormalizedEntity::class, $entity->pk, $entity->id)]))->first();

        static::assertInstanceOf(NormalizedEntity::class, $read);
        static::assertTrue($entity->id->equals($read->id));
        static::assertSame($entity->pk, $read->pk);
    }

    public function testPutLeavesTheEntitysOwnValuesIntact(): void
    {
        $entity = RecordEntity::create(self::TENANT, 'a', name: 'kept', counter: 3, tags: ['x']);
        $entity->meta = ['k' => 'v'];

        $this->writer()->put(new PutInput($entity));

        static::assertSame('kept', $entity->name);
        static::assertSame(3, $entity->counter);
        static::assertSame(['x'], $entity->tags);
        static::assertSame(['k' => 'v'], $entity->meta);
    }

    public function testABatchWritesEveryPut(): void
    {
        $this->writer()->batchWrite(new BatchWriteInput()->withPut(
            RecordEntity::create(self::TENANT, 'a'),
            RecordEntity::create(self::TENANT, 'b'),
            RecordEntity::create(self::TENANT, 'c'),
        ));

        static::assertSame(3, $this->countRecords());
    }

    /**
     * `BatchWriteItem` caps at 25 operations, so a larger set has to be chunked.
     */
    public function testABatchPastTheLimitChunksAndWritesEveryPut(): void
    {
        $entities = [];
        for ($i = 0; $i < 60; ++$i) {
            $entities[] = RecordEntity::create(self::TENANT, \sprintf('id-%02d', $i));
        }

        $this->writer()->batchWrite(new BatchWriteInput()->withPut(...$entities));

        static::assertSame(60, $this->countRecords());
    }

    public function testPutSingleWithConditionExpressionThrowsWhenTheConditionFails(): void
    {
        $this->writer()->put(new PutInput(RecordEntity::create(self::TENANT, 'a', name: 'first')));

        static::expectException(ConditionalCheckFailedException::class);

        $this->writer()->put(new PutInput(
            RecordEntity::create(self::TENANT, 'a', name: 'second'),
            Filter::notExists('id'),
        ));
    }

    public function testATransactionOfConditionalPutsWritesNoneWhenOneFails(): void
    {
        $this->writer()->put(new PutInput(RecordEntity::create(self::TENANT, 'b', name: 'taken')));

        try {
            $this->writer()->transactWrite(new TransactWriteInput()->with(
                new PutInput(RecordEntity::create(self::TENANT, 'a'), Filter::notExists('id')),
                new PutInput(RecordEntity::create(self::TENANT, 'b'), Filter::notExists('id')),
            ));
            static::fail('The conflicting condition should have cancelled the transaction.');
        } catch (TransactionCanceledException) {
            // Expected; what matters is that neither write landed.
        }

        static::assertNull($this->read('a'));
        static::assertSame('taken', $this->read('b')?->name);
    }

    public function testUpdateSingleSetsFields(): void
    {
        $this->writer()->put(new PutInput(RecordEntity::create(self::TENANT, 'a', name: 'before', counter: 1)));

        $this->writer()->update(new UpdateInput(new Key(RecordEntity::class, self::TENANT, 'a'), ['name' => 'after']));

        $read = $this->read('a');
        static::assertSame('after', $read?->name);
        static::assertSame(1, $read->counter, 'an update must leave the fields it was not given alone');
    }

    public function testUpdateWithNullValueRemovesTheAttribute(): void
    {
        $this->writer()->put(new PutInput(RecordEntity::create(self::TENANT, 'a', name: 'set')));

        $this->writer()->update(new UpdateInput(new Key(RecordEntity::class, self::TENANT, 'a'), ['name' => null]));

        static::assertNull($this->read('a')?->name);
    }

    public function testUpdateSetsAndRemovesFieldsInOneExpression(): void
    {
        $this->writer()->put(new PutInput(RecordEntity::create(self::TENANT, 'a', name: 'set', counter: 1)));

        $this->writer()->update(new UpdateInput(new Key(RecordEntity::class, self::TENANT, 'a'), [
            'name' => null,
            'counter' => 9,
        ]));

        $read = $this->read('a');
        static::assertNull($read?->name);
        static::assertSame(9, $read?->counter);
    }

    public function testUpdateByEntityRefreshesTheEntityFromTheStoredRow(): void
    {
        $entity = NormalizedEntity::create(self::TENANT, 'invoice');
        $this->writer()->put(new PutInput($entity));

        $this->writer()->update(new UpdateInput($entity, ['label' => 'renamed']));

        static::assertSame('renamed', $entity->label);
    }

    /**
     * The refresh reads the whole row, not just what was written, so a field this update never mentioned
     * arrives too.
     */
    public function testUpdateByEntityRefreshesFieldsItNeverWrote(): void
    {
        $entity = NormalizedEntity::create(self::TENANT, 'invoice');
        $this->writer()->put(new PutInput($entity));

        $stored = $this->readNormalized($entity);
        static::assertNotNull($stored);

        // Somebody else moves the row on, then this update touches an unrelated field.
        $this->writer()->update(new UpdateInput(new Key(NormalizedEntity::class, $stored->pk, $stored->id), ['kind' => 'credit']));
        $this->writer()->update(new UpdateInput($entity, ['label' => 'renamed']));

        static::assertSame('renamed', $entity->label);
        static::assertSame('credit', $entity->kind);
    }

    public function testUpdateByKeyWritesTheRowAndHasNoEntityToRefresh(): void
    {
        $entity = NormalizedEntity::create(self::TENANT, 'invoice');
        $this->writer()->put(new PutInput($entity));

        $this->writer()->update(new UpdateInput(new Key(NormalizedEntity::class, $entity->pk, $entity->id), ['label' => 'renamed']));

        static::assertSame('renamed', $this->readNormalized($entity)?->label);
        static::assertSame(NormalizedEntityNormalizer::DEFAULT_LABEL, $entity->label);
    }

    /**
     * The normalizer is told it runs for an update, so it stamps one without the caller naming the field. A put
     * may be the row's first write, so it stamps nothing.
     */
    public function testUpdateWritesWhatTheNormalizerAddsForAnUpdate(): void
    {
        $entity = NormalizedEntity::create(self::TENANT, 'invoice');
        $this->writer()->put(new PutInput($entity));

        $put = $this->readNormalized($entity);

        $this->writer()->update(new UpdateInput(new Key(NormalizedEntity::class, $entity->pk, $entity->id), ['label' => 'renamed']));

        $updated = $this->readNormalized($entity);

        static::assertNotNull($put);
        static::assertNull($put->updatedAt);
        static::assertSame(NormalizedEntityNormalizer::UPDATED_AT, $updated?->updatedAt?->getTimestamp());
    }

    public function testATransactionWritesEveryUpdate(): void
    {
        $this->writer()->batchWrite(new BatchWriteInput()->withPut(
            RecordEntity::create(self::TENANT, 'a'),
            RecordEntity::create(self::TENANT, 'b'),
        ));

        $this->writer()->transactWrite(new TransactWriteInput()->with(
            new UpdateInput(new Key(RecordEntity::class, self::TENANT, 'a'), ['name' => 'one']),
            new UpdateInput(new Key(RecordEntity::class, self::TENANT, 'b'), ['name' => 'two']),
        ));

        static::assertSame('one', $this->read('a')?->name);
        static::assertSame('two', $this->read('b')?->name);
    }

    public function testATransactionOfUpdatesByEntityBackfillsEachEntity(): void
    {
        $first = NormalizedEntity::create(self::TENANT, 'first');
        $second = NormalizedEntity::create(self::TENANT, 'second');
        $this->writer()->batchWrite(new BatchWriteInput()->withPut($first, $second));

        $this->writer()->transactWrite(new TransactWriteInput()->with(
            new UpdateInput($first, ['label' => 'one']),
            new UpdateInput($second, ['label' => 'two']),
        ));

        static::assertSame('one', $first->label);
        static::assertSame('two', $second->label);
        // A transaction returns no item, so what the normalizer added is applied back like what the caller wrote.
        static::assertSame(NormalizedEntityNormalizer::UPDATED_AT, $first->updatedAt?->getTimestamp());
        static::assertSame(NormalizedEntityNormalizer::UPDATED_AT, $second->updatedAt?->getTimestamp());
    }

    public function testUpdateSingleWithConditionThrowsWhenTheConditionFails(): void
    {
        $this->writer()->put(new PutInput(RecordEntity::create(self::TENANT, 'a', RecordStatus::Done)));

        static::expectException(ConditionalCheckFailedException::class);

        $this->writer()->update(new UpdateInput(
            new Key(RecordEntity::class, self::TENANT, 'a'),
            ['name' => 'nope'],
            Filter::equals('status', RecordStatus::Open),
        ));
    }

    /**
     * `UpdateItem` alone would create the item from the key and the updated fields, leaving a row that
     * fails to read back for every required field it lacks.
     */
    public function testUpdateSingleRefusesAMissingItemAndCreatesNone(): void
    {
        try {
            $this->writer()->update(new UpdateInput(new Key(RecordEntity::class, self::TENANT, 'ghost'), ['name' => 'nope']));
            static::fail('An update of a missing item should fail its condition.');
        } catch (ConditionalCheckFailedException) {
            // Expected.
        }

        static::assertSame(0, $this->countRecords());
    }

    public function testUpdateByEntityRefusesADeletedItemAndLeavesTheEntityAlone(): void
    {
        $entity = NormalizedEntity::create(self::TENANT, 'invoice');
        $this->writer()->put(new PutInput($entity));
        $this->writer()->delete(new DeleteInput($entity));

        try {
            $this->writer()->update(new UpdateInput($entity, ['label' => 'renamed']));
            static::fail('An update of a deleted item should fail its condition.');
        } catch (ConditionalCheckFailedException) {
            // Expected.
        }

        static::assertNull($this->readNormalized($entity));
        static::assertSame(NormalizedEntityNormalizer::DEFAULT_LABEL, $entity->label);
    }

    /**
     * Both parts of the caller's condition hold for an item that does not exist, so only the existence
     * check stops it, and it has to bind to the whole disjunction rather than its first operand.
     */
    public function testUpdateWithAConditionAMissingItemSatisfiesStillRequiresTheItem(): void
    {
        static::expectException(ConditionalCheckFailedException::class);

        $this->writer()->update(new UpdateInput(
            new Key(RecordEntity::class, self::TENANT, 'ghost'),
            ['name' => 'nope'],
            Filter::or(Filter::notExists('name'), Filter::notEquals('status', RecordStatus::Done)),
        ));
    }

    public function testUpdateWithTheCallersOwnExistenceConditionStillApplies(): void
    {
        $this->writer()->put(new PutInput(RecordEntity::create(self::TENANT, 'a')));

        $this->writer()->update(new UpdateInput(
            new Key(RecordEntity::class, self::TENANT, 'a'),
            ['name' => 'after'],
            Filter::exists('tenantId'),
        ));

        static::assertSame('after', $this->read('a')?->name);
    }

    public function testATransactionRefusesAMissingItemAndRollsBackTheOtherUpdates(): void
    {
        $this->writer()->put(new PutInput(RecordEntity::create(self::TENANT, 'a', name: 'before')));

        try {
            $this->writer()->transactWrite(new TransactWriteInput()->with(
                new UpdateInput(new Key(RecordEntity::class, self::TENANT, 'a'), ['name' => 'after']),
                new UpdateInput(new Key(RecordEntity::class, self::TENANT, 'ghost'), ['name' => 'nope']),
            ));
            static::fail('The update of the missing item should have cancelled the transaction.');
        } catch (TransactionCanceledException) {
            // Expected.
        }

        static::assertSame('before', $this->read('a')?->name);
        static::assertNull($this->read('ghost'));
    }

    public function testDeleteSingleRemovesTheItem(): void
    {
        $this->writer()->put(new PutInput(RecordEntity::create(self::TENANT, 'a')));

        $this->writer()->delete(new DeleteInput(new Key(RecordEntity::class, self::TENANT, 'a')));

        static::assertNull($this->read('a'));
    }

    public function testDeleteSingleIsIdempotentForAnAbsentKey(): void
    {
        $this->writer()->delete(new DeleteInput(new Key(RecordEntity::class, self::TENANT, 'never-written')));

        static::assertSame(0, $this->countRecords());
    }

    public function testDeleteByEntityReadsTheKeyOffTheEntity(): void
    {
        $entity = RecordEntity::create(self::TENANT, 'a');
        $this->writer()->put(new PutInput($entity));

        $this->writer()->delete(new DeleteInput($entity));

        static::assertNull($this->read('a'));
    }

    public function testABatchRemovesEveryDelete(): void
    {
        foreach (['a', 'b', 'c'] as $id) {
            $this->writer()->put(new PutInput(RecordEntity::create(self::TENANT, $id)));
        }

        $this->writer()->batchWrite(new BatchWriteInput()->withDelete(
            new Key(RecordEntity::class, self::TENANT, 'a'),
            new Key(RecordEntity::class, self::TENANT, 'b'),
        ));

        static::assertSame(1, $this->countRecords());
        static::assertNotNull($this->read('c'));
    }

    public function testABatchPastTheLimitChunksAndRemovesEveryDelete(): void
    {
        $entities = [];
        $keys = [];
        for ($i = 0; $i < 60; ++$i) {
            $id = \sprintf('id-%02d', $i);
            $entities[] = RecordEntity::create(self::TENANT, $id);
            $keys[] = new Key(RecordEntity::class, self::TENANT, $id);
        }
        $this->writer()->batchWrite(new BatchWriteInput()->withPut(...$entities));

        $this->writer()->batchWrite(new BatchWriteInput()->withDelete(...$keys));

        static::assertSame(0, $this->countRecords());
    }

    public function testDeleteSingleWithConditionThrowsWhenTheConditionFails(): void
    {
        $this->writer()->put(new PutInput(RecordEntity::create(self::TENANT, 'a', RecordStatus::Open)));

        static::expectException(ConditionalCheckFailedException::class);

        $this->writer()->delete(new DeleteInput(
            new Key(RecordEntity::class, self::TENANT, 'a'),
            Filter::equals('status', RecordStatus::Done),
        ));
    }

    public function testATransactionOfConditionalDeletesRemovesNoneWhenOneFails(): void
    {
        $this->writer()->batchWrite(new BatchWriteInput()->withPut(
            RecordEntity::create(self::TENANT, 'a', RecordStatus::Open),
            RecordEntity::create(self::TENANT, 'b', RecordStatus::Done),
        ));

        try {
            $this->writer()->transactWrite(new TransactWriteInput()->with(
                new DeleteInput(new Key(RecordEntity::class, self::TENANT, 'a'), Filter::equals('status', RecordStatus::Open)),
                new DeleteInput(new Key(RecordEntity::class, self::TENANT, 'b'), Filter::equals('status', RecordStatus::Open)),
            ));
            static::fail('The failing condition should have cancelled the transaction.');
        } catch (TransactionCanceledException) {
            // Expected; neither delete may have landed.
        }

        static::assertSame(2, $this->countRecords());
    }

    public function testTransactWriteAppliesPutUpdateAndDeleteTogether(): void
    {
        $this->writer()->batchWrite(new BatchWriteInput()->withPut(
            RecordEntity::create(self::TENANT, 'update-me', name: 'before'),
            RecordEntity::create(self::TENANT, 'delete-me'),
        ));

        $this->writer()->transactWrite(new TransactWriteInput()
            ->with(new PutInput(RecordEntity::create(self::TENANT, 'new')))
            ->with(new UpdateInput(new Key(RecordEntity::class, self::TENANT, 'update-me'), ['name' => 'after']))
            ->with(new DeleteInput(new Key(RecordEntity::class, self::TENANT, 'delete-me'))));

        static::assertNotNull($this->read('new'));
        static::assertSame('after', $this->read('update-me')?->name);
        static::assertNull($this->read('delete-me'));
    }

    public function testTransactWriteSpansSeveralTables(): void
    {
        $this->writer()->transactWrite(new TransactWriteInput()
            ->with(new PutInput(RecordEntity::create(self::TENANT, 'a')))
            ->with(new PutInput(ArchiveEntity::create('arch-1', 'kept'))));

        static::assertNotNull($this->read('a'));

        $archived = $this->client()->get(new GetInput([new Key(ArchiveEntity::class, 'arch-1')]))->first();
        static::assertInstanceOf(ArchiveEntity::class, $archived);
        static::assertSame('kept', $archived->label);
    }

    public function testABatchSpansSeveralTables(): void
    {
        $this->writer()->put(new PutInput(RecordEntity::create(self::TENANT, 'gone')));

        $this->writer()->batchWrite(new BatchWriteInput()
            ->withPut(ArchiveEntity::create('arch-1', 'kept'))
            ->withDelete(new Key(RecordEntity::class, self::TENANT, 'gone'))
            ->withPut(RecordEntity::create(self::TENANT, 'a')));

        static::assertNull($this->read('gone'));
        static::assertNotNull($this->read('a'));
        static::assertSame('kept', $this->client()->find(new Key(ArchiveEntity::class, 'arch-1'))?->label);
    }

    public function testTransactWriteIsAtomicAndRollsBackWhenAConditionFails(): void
    {
        $this->writer()->put(new PutInput(RecordEntity::create(self::TENANT, 'taken')));

        try {
            $this->writer()->transactWrite(new TransactWriteInput()
                ->with(new PutInput(RecordEntity::create(self::TENANT, 'fresh')))
                ->with(new PutInput(RecordEntity::create(self::TENANT, 'taken'), Filter::notExists('id'))));
            static::fail('The failing condition should have cancelled the transaction.');
        } catch (TransactionCanceledException) {
            // Expected.
        }

        static::assertNull($this->read('fresh'), 'the sibling write has to be rolled back with the transaction');
    }

    /**
     * DynamoDB reports one cancellation reason per operation, by position, so the position has to be the one the
     * operation was added at, even when an entity class comes back after another one.
     */
    public function testTransactWriteReportsCancellationReasonsInTheOrderTheOperationsWereAdded(): void
    {
        $this->writer()->put(new PutInput(RecordEntity::create(self::TENANT, 'taken')));

        try {
            $this->writer()->transactWrite(new TransactWriteInput()
                ->with(new PutInput(RecordEntity::create(self::TENANT, 'fresh')))
                ->with(new PutInput(ArchiveEntity::create('arch-1')))
                ->with(new PutInput(RecordEntity::create(self::TENANT, 'taken'), Filter::notExists('id'))));
            static::fail('The failing condition should have cancelled the transaction.');
        } catch (TransactionCanceledException $exception) {
            static::assertSame(
                ['None', 'None', 'ConditionalCheckFailed'],
                array_map(static fn (CancellationReason $reason): ?string => $reason->getCode(), $exception->getCancellationReasons()),
            );
        }
    }

    public function testTransactWriteUpdateByEntityBackfillsTheEntity(): void
    {
        $entity = NormalizedEntity::create(self::TENANT, 'invoice');
        $this->writer()->put(new PutInput($entity));

        $this->writer()->transactWrite(new TransactWriteInput()
            ->with(new UpdateInput($entity, ['label' => 'transacted'])));

        static::assertSame('transacted', $entity->label);
    }

    public function testUpdateOptedOutOfTheRefreshWritesTheRowAndLeavesTheEntityAlone(): void
    {
        $entity = NormalizedEntity::create(self::TENANT, 'invoice');
        $this->writer()->put(new PutInput($entity));

        $this->writer()->update(new UpdateInput($entity, ['label' => 'renamed'], refresh: Refresh::None));

        static::assertSame('renamed', $this->readNormalized($entity)?->label);
        static::assertSame(NormalizedEntityNormalizer::DEFAULT_LABEL, $entity->label);
    }

    /**
     * The readback spans the tables the transaction touched, matching each row to the entity it was keyed
     * by rather than to whichever came back first.
     */
    public function testTransactWriteBackfillsEntitiesAcrossTables(): void
    {
        $record = RecordEntity::create(self::TENANT, 'a');
        $record->meta = ['first' => 'one'];
        $archive = ArchiveEntity::create('arch-1');
        $archive->meta = ['first' => 'uno'];
        $this->writer()->put(new PutInput($record));
        $this->writer()->put(new PutInput($archive));

        $this->writer()->transactWrite(new TransactWriteInput()
            ->with(new UpdateInput($record, ['meta.first' => 'one-renewed']))
            ->with(new UpdateInput($archive, ['meta.first' => 'uno-renewed'])));

        static::assertSame(['first' => 'one-renewed'], $record->meta);
        static::assertSame(['first' => 'uno-renewed'], $archive->meta);
    }

    /**
     * A whole attribute is written with exactly the value that was sent, so the entity is filled from that
     * and the transaction costs no read. The untouched `counter` is the proof: a readback would have picked
     * up the out-of-band write, applying what was sent cannot.
     */
    public function testTransactWriteAppliesWholeAttributesWithoutReadingTheRowBack(): void
    {
        $entity = RecordEntity::create(self::TENANT, 'a', name: 'before');
        $this->writer()->put(new PutInput($entity));

        $this->writer()->update(new UpdateInput(new Key(RecordEntity::class, self::TENANT, 'a'), ['counter' => 9]));

        $this->writer()->transactWrite(new TransactWriteInput()
            ->with(new UpdateInput($entity, ['name' => 'after'])));

        static::assertSame('after', $entity->name);
        static::assertSame(0, $entity->counter);
        static::assertSame(9, $this->read('a')?->counter);
    }

    /**
     * An attribute written as null is removed from the row, and the property goes with it.
     */
    public function testTransactWriteAppliesARemovedAttributeAsNullOnTheEntity(): void
    {
        $entity = RecordEntity::create(self::TENANT, 'a', name: 'before');
        $this->writer()->put(new PutInput($entity));

        $this->writer()->transactWrite(new TransactWriteInput()
            ->with(new UpdateInput($entity, ['name' => null])));

        static::assertNull($entity->name);
        static::assertNull($this->read('a')?->name);
    }

    /**
     * `refresh: Refresh::WithoutReadBack` buys no read, so a nested path leaves its attribute behind — but the whole attributes
     * written alongside it still apply.
     */
    public function testTransactWriteWithoutAReadbackStillAppliesWholeAttributes(): void
    {
        $entity = RecordEntity::create(self::TENANT, 'a', name: 'before');
        $entity->meta = ['first' => 'one', 'second' => 'two'];
        $this->writer()->put(new PutInput($entity));

        $this->writer()->transactWrite(new TransactWriteInput()
            ->with(new UpdateInput($entity, [
                'name' => 'after',
                'meta.first' => 'one-renewed',
            ], refresh: Refresh::WithoutReadBack)));

        static::assertSame('after', $entity->name);
        static::assertSame(['first' => 'one', 'second' => 'two'], $entity->meta);
        static::assertSame('one-renewed', $this->read('a')?->meta['first'] ?? null);
    }

    /**
     * A nested path is why the row has to be read back rather than reconstructed: `meta.first` is not a
     * property, so nothing local can say what `meta` holds after the write.
     */
    public function testTransactWriteBackfillsAWholeMapItWroteOneEntryOf(): void
    {
        $entity = RecordEntity::create(self::TENANT, 'a');
        $entity->meta = ['first' => 'one', 'second' => 'two'];
        $this->writer()->put(new PutInput($entity));

        $this->writer()->transactWrite(new TransactWriteInput()
            ->with(new UpdateInput($entity, ['meta.first' => 'one-renewed'])));

        static::assertSame(['first' => 'one-renewed', 'second' => 'two'], $entity->meta);
    }

    /**
     * A transaction caps at 100 operations, so a larger set is chunked — and stops being atomic across
     * the chunks, which is exactly why the limit is worth pinning down.
     */
    public function testTransactWriteChunksBeyondTheTransactionLimit(): void
    {
        $input = new TransactWriteInput();
        for ($i = 0; $i < 120; ++$i) {
            $input = $input->with(new PutInput(RecordEntity::create(self::TENANT, \sprintf('id-%03d', $i))));
        }

        $this->writer()->transactWrite($input);

        static::assertSame(120, $this->countRecords());
    }

    private function writer(): WriterClient
    {
        $writer = $this->container()->get('test.' . WriterClient::class);
        static::assertInstanceOf(WriterClient::class, $writer);

        return $writer;
    }

    private function read(string $id): ?RecordEntity
    {
        $entity = $this->client()->get(new GetInput([new Key(RecordEntity::class, self::TENANT, $id)]))->first();
        static::assertTrue($entity === null || $entity instanceof RecordEntity);

        /** @var ?RecordEntity $entity */
        return $entity;
    }

    private function readNormalized(NormalizedEntity $entity): ?NormalizedEntity
    {
        $read = $this->client()->get(new GetInput([new Key(NormalizedEntity::class, $entity->pk, $entity->id)]))->first();
        static::assertTrue($read === null || $read instanceof NormalizedEntity);

        /** @var ?NormalizedEntity $read */
        return $read;
    }

    private function countRecords(): int
    {
        return $this->client()->count(new ScanInput(RecordEntity::class));
    }
}
