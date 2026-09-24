<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Integration\DynamoDb;

use AsyncAws\DynamoDb\Exception\ConditionalCheckFailedException;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;
use PHPUnit\Framework\Attributes\CoversNothing;
use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Client\Index;
use Shopware\DynamodbDalBundle\Client\Input\DeleteInput;
use Shopware\DynamodbDalBundle\Client\Input\GetInput;
use Shopware\DynamodbDalBundle\Client\Input\PutInput;
use Shopware\DynamodbDalBundle\Client\Input\QueryInput;
use Shopware\DynamodbDalBundle\Client\Input\ScanInput;
use Shopware\DynamodbDalBundle\Client\Input\UpdateInput;
use Shopware\DynamodbDalBundle\Expression\Filter;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\DynamoDbTestKernel;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\Entity\NormalizedEntity;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\Entity\NormalizedEntityNormalizer;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\Entity\RecordEntity;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\Entity\RecordStatus;
use Symfony\Component\Uid\Uuid;

/**
 * The shapes repositories built on this DAL keep reaching for — claim-once inserts, guarded state
 * transitions, partial updates that must not clobber a concurrent writer, soft deletes, normalizer
 * round trips, tenant scoping and batch deletes.
 *
 * Each of these is a DAL behaviour that only a real DynamoDB can confirm.
 */
#[CoversNothing]
class RepositoryBehaviourTest extends DynamoDbTestCase
{
    public function testInsertIfAbsentClaimsOnceThenRejectsDuplicates(): void
    {
        $claim = static fn (string $name): PutInput => new PutInput(
            RecordEntity::create(self::TENANT, 'claim', name: $name),
            Filter::notExists('id'),
        );

        $this->client()->put(RecordEntity::class, $claim('first'));

        try {
            $this->client()->put(RecordEntity::class, $claim('second'));
            static::fail('The second claim should have been rejected.');
        } catch (ConditionalCheckFailedException) {
            // Expected: the row is already claimed.
        }

        static::assertSame('first', $this->read('claim')?->name);
    }

    public function testGuardedTransitionAppliesWhileTheConditionHolds(): void
    {
        $this->put(RecordEntity::create(self::TENANT, 'a', RecordStatus::Open));

        $this->client()->update(RecordEntity::class, new UpdateInput(
            new Index(self::TENANT, 'a'),
            ['status' => RecordStatus::Done],
            Filter::equals('status', RecordStatus::Open),
        ));

        static::assertSame(RecordStatus::Done, $this->read('a')?->status);
    }

    public function testGuardedTransitionIsRefusedOnceTheStateMoved(): void
    {
        $this->put(RecordEntity::create(self::TENANT, 'a', RecordStatus::Done));

        try {
            $this->client()->update(RecordEntity::class, new UpdateInput(
                new Index(self::TENANT, 'a'),
                ['status' => RecordStatus::Failed],
                Filter::equals('status', RecordStatus::Open),
            ));
            static::fail('The guard should have refused the transition.');
        } catch (ConditionalCheckFailedException) {
            // Expected.
        }

        static::assertSame(RecordStatus::Done, $this->read('a')?->status);
    }

    /**
     * A partial update names only the fields it changes, so a writer that touched another field in the
     * meantime keeps its value — which is the whole reason to update rather than re-put an entity read
     * earlier.
     */
    public function testPartialUpdateKeepsAConcurrentWriteToAnotherField(): void
    {
        $this->put(RecordEntity::create(self::TENANT, 'a', name: 'original', counter: 1));

        // Someone else bumps the counter between our read and our write.
        $this->client()->update(RecordEntity::class, new UpdateInput(new Index(self::TENANT, 'a'), ['counter' => 99]));

        $this->client()->update(RecordEntity::class, new UpdateInput(new Index(self::TENANT, 'a'), ['name' => 'renamed']));

        $read = $this->read('a');
        static::assertSame('renamed', $read?->name);
        static::assertSame(99, $read?->counter);
    }

    /**
     * `UpdateItem` creates the row when it is missing, so a write that must not resurrect something
     * deleted since it was read has to require the key itself.
     */
    public function testUpdateDoesNotRecreateARowDeletedSinceItWasRead(): void
    {
        $this->put(RecordEntity::create(self::TENANT, 'a'));
        $this->client()->delete(RecordEntity::class, new DeleteInput(new Index(self::TENANT, 'a')));

        try {
            $this->client()->update(RecordEntity::class, new UpdateInput(
                new Index(self::TENANT, 'a'),
                ['name' => 'resurrected'],
                Filter::exists('id'),
            ));
            static::fail('The condition should have refused to recreate the row.');
        } catch (ConditionalCheckFailedException) {
            // Expected.
        }

        static::assertNull($this->read('a'));
        static::assertSame(0, $this->client()->count(RecordEntity::class, new ScanInput()));
    }

    public function testSoftDeleteMarkerHidesAndUnhidesTheRow(): void
    {
        $this->put(RecordEntity::create(self::TENANT, 'a'));

        $live = static fn (): QueryInput => new QueryInput(
            Filter::equals('tenantId', self::TENANT),
            filter: Filter::notExists('deletedAt'),
        );

        static::assertCount(1, $this->client()->search(RecordEntity::class, $live())->toArray());

        $this->client()->update(RecordEntity::class, new UpdateInput(
            new Index(self::TENANT, 'a'),
            ['deletedAt' => new \DateTimeImmutable('@1700000001')],
        ));
        static::assertCount(0, $this->client()->search(RecordEntity::class, $live())->toArray());

        // Clearing the marker is an update to null, which removes the attribute rather than storing one.
        $this->client()->update(RecordEntity::class, new UpdateInput(new Index(self::TENANT, 'a'), ['deletedAt' => null]));
        static::assertCount(1, $this->client()->search(RecordEntity::class, $live())->toArray());
    }

    public function testNormalizerComposesTheKeyOnWriteAndTheRowIsFoundUnderIt(): void
    {
        $entity = NormalizedEntity::create(self::TENANT, 'invoice');
        $this->client()->put(NormalizedEntity::class, new PutInput($entity));

        $found = $this->client()->get(new GetInput([
            NormalizedEntity::class => [new Index(self::TENANT . '#invoice', $entity->id)],
        ]))->first();

        static::assertInstanceOf(NormalizedEntity::class, $found);
        static::assertSame(self::TENANT, $found->tenantId);
        static::assertSame('invoice', $found->kind);
        static::assertSame(NormalizedEntityNormalizer::DEFAULT_LABEL, $found->label);
    }

    /**
     * A row written before a required field existed carries no value for it. Filling it in on read is
     * what keeps the deserialization from failing over it.
     */
    public function testNormalizerFillsAFieldMissingFromAnOlderRowOnRead(): void
    {
        $id = Uuid::v7();

        $this->dynamo()->putItem([
            'TableName' => DynamoDbTestKernel::TABLES['normalized'],
            'Item' => [
                'pk' => new AttributeValue(['S' => self::TENANT . '#legacy']),
                'id' => new AttributeValue(['S' => $id->toString()]),
                'tenantId' => new AttributeValue(['S' => self::TENANT]),
                'kind' => new AttributeValue(['S' => 'legacy']),
                'createdAt' => new AttributeValue(['N' => '1700000000']),
                // no `label`
            ],
        ])->resolve();

        $found = $this->client()->get(new GetInput([
            NormalizedEntity::class => [new Index(self::TENANT . '#legacy', $id)],
        ]))->first();

        static::assertInstanceOf(NormalizedEntity::class, $found);
        static::assertSame(NormalizedEntityNormalizer::DEFAULT_LABEL, $found->label);
    }

    public function testEmptyListAndMapSurviveARoundTrip(): void
    {
        $entity = RecordEntity::create(self::TENANT, 'a');
        $entity->tags = [];
        $entity->meta = [];
        $entity->groups = [];
        $this->put($entity);

        $read = $this->read('a');
        static::assertSame([], $read?->tags);
        static::assertSame([], $read?->meta);
        static::assertSame([], $read?->groups);
    }

    public function testJsonFieldRoundTripsANestedStructure(): void
    {
        $entity = RecordEntity::create(self::TENANT, 'a');
        $entity->payload = ['amount' => ['value' => '10.00', 'currency' => 'EUR'], 'items' => [1, 2, 3]];
        $this->put($entity);

        static::assertSame($entity->payload, $this->read('a')?->payload);
    }

    public function testANullableFieldThatWasNeverSetStaysAbsentFromTheRow(): void
    {
        $this->put(RecordEntity::create(self::TENANT, 'a'));

        $item = $this->dynamo()->getItem([
            'TableName' => DynamoDbTestKernel::TABLES['record'],
            'Key' => [
                'tenantId' => new AttributeValue(['S' => self::TENANT]),
                'id' => new AttributeValue(['S' => 'a']),
            ],
        ])->getItem();

        // DynamoDB has no null attribute, so an unset value is simply not written.
        static::assertArrayNotHasKey('name', $item);
        static::assertArrayNotHasKey('deletedAt', $item);
        static::assertNull($this->read('a')?->name);
    }

    public function testBatchDeleteIgnoresUnknownKeysAndRemovesTheKnownOnes(): void
    {
        $this->put(RecordEntity::create(self::TENANT, 'a'));
        $this->put(RecordEntity::create(self::TENANT, 'b'));

        $this->client()->delete(
            RecordEntity::class,
            new DeleteInput(new Index(self::TENANT, 'a')),
            new DeleteInput(new Index(self::TENANT, 'never-written')),
        );

        static::assertNull($this->read('a'));
        static::assertNotNull($this->read('b'));
    }

    public function testBatchDeleteWithNoKeysIsANoOp(): void
    {
        $this->put(RecordEntity::create(self::TENANT, 'a'));

        $this->client()->delete(RecordEntity::class);

        static::assertNotNull($this->read('a'));
    }

    public function testScanYieldsEveryStoredRowAcrossPartitions(): void
    {
        $this->put(RecordEntity::create(self::TENANT, 'a'));
        $this->put(RecordEntity::create('tenant-2', 'b'));
        $this->put(RecordEntity::create('tenant-3', 'c'));

        static::assertCount(3, $this->client()->search(RecordEntity::class, new ScanInput())->toArray());
    }

    public function testATenantScopedQueryNeverCrossesPartitions(): void
    {
        $this->put(RecordEntity::create(self::TENANT, 'shared-id'));
        $this->put(RecordEntity::create('tenant-2', 'shared-id'));

        $found = $this->client()
            ->search(RecordEntity::class, new QueryInput(Filter::equals('tenantId', self::TENANT)))
            ->toArray();

        static::assertCount(1, $found);
        static::assertInstanceOf(RecordEntity::class, $found[0]);
        static::assertSame(self::TENANT, $found[0]->tenantId);
    }

    public function testCountByStatusReadsTheIndexRatherThanTheTable(): void
    {
        $this->put(RecordEntity::create(self::TENANT, 'a', RecordStatus::Open));
        $this->put(RecordEntity::create('tenant-2', 'b', RecordStatus::Open, new \DateTimeImmutable('@1700000001')));
        $this->put(RecordEntity::create(self::TENANT, 'c', RecordStatus::Done));

        $open = $this->client()->count(RecordEntity::class, new QueryInput(
            Filter::equals('status', RecordStatus::Open),
            index: 'statusIndex',
        ));

        static::assertSame(2, $open);
    }

    private function put(AbstractEntity $entity): void
    {
        $this->client()->put(RecordEntity::class, new PutInput($entity));
    }

    private function read(string $id): ?RecordEntity
    {
        $entity = $this->client()->get(new GetInput([RecordEntity::class => [new Index(self::TENANT, $id)]]))->first();
        static::assertTrue($entity === null || $entity instanceof RecordEntity);

        /** @var ?RecordEntity $entity */
        return $entity;
    }
}
