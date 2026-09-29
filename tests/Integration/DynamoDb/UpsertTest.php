<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Integration\DynamoDb;

use AsyncAws\DynamoDb\Exception\ConditionalCheckFailedException;
use PHPUnit\Framework\Attributes\CoversClass;
use Shopware\DynamodbDalBundle\Client\Input\GetInput;
use Shopware\DynamodbDalBundle\Client\Input\PutInput;
use Shopware\DynamodbDalBundle\Client\Input\UpsertInput;
use Shopware\DynamodbDalBundle\Client\Key;
use Shopware\DynamodbDalBundle\Client\Output\UpsertOutcome;
use Shopware\DynamodbDalBundle\Client\Write\WriteRequestFactory;
use Shopware\DynamodbDalBundle\Client\Write\WriterClient;
use Shopware\DynamodbDalBundle\Exception\FieldMissingSerializedValueException;
use Shopware\DynamodbDalBundle\Expression\Filter;
use Shopware\DynamodbDalBundle\Expression\Update;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\Entity\NormalizedEntity;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\Entity\NormalizedEntityNormalizer;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\Entity\RecordEntity;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\Entity\RecordStatus;
use Symfony\Component\Uid\Uuid;

/**
 * Upserts against a real table: where no row is stored, the entity is put, and a stored row takes the update and
 * keeps everything else.
 */
#[CoversClass(WriterClient::class)]
#[CoversClass(WriteRequestFactory::class)]
class UpsertTest extends DynamoDbTestCase
{
    public function testAnUpsertPutsTheEntityWhereNoRowIsStored(): void
    {
        static::assertSame(UpsertOutcome::Created, $this->client()->upsert(new UpsertInput(RecordEntity::create(self::TENANT, 'a', name: 'new', counter: 2), ['status'])));

        $read = $this->read('a');
        static::assertSame('new', $read?->name);
        static::assertSame(2, $read->counter);
    }

    /**
     * A field it doesn't name keeps its stored value, and so does every other entry of a map it names an entry of.
     */
    public function testAStoredRowTakesOnlyTheNamedPathsFromTheEntity(): void
    {
        $stored = RecordEntity::create(self::TENANT, 'a', name: 'stored', counter: 5);
        $stored->meta = ['carrier' => 'dhl', 'tracking' => 't-1'];
        $this->client()->put(new PutInput($stored));

        $entity = RecordEntity::create(self::TENANT, 'a', RecordStatus::Done, new \DateTimeImmutable('@1800000000'), name: 'new');
        $entity->meta = ['carrier' => 'ups'];
        static::assertSame(UpsertOutcome::Updated, $this->client()->upsert(new UpsertInput($entity, ['status', 'meta.carrier'])));

        $read = $this->read('a');
        static::assertSame(RecordStatus::Done, $read?->status);
        static::assertEquals(['carrier' => 'ups', 'tracking' => 't-1'], $read->meta);
        static::assertSame('stored', $read->name);
        static::assertSame(5, $read->counter);
        static::assertSame(1_700_000_000, $read->createdAt->getTimestamp());
    }

    /**
     * The map entry is written where the row is stored, and the whole entity, map included, where it is not.
     */
    public function testAnUpsertOfAMapEntryPutsTheWholeMapWhereNoRowIsStored(): void
    {
        $entity = RecordEntity::create(self::TENANT, 'a');
        $entity->meta = ['carrier' => 'dhl'];

        static::assertSame(UpsertOutcome::Created, $this->client()->upsert(new UpsertInput($entity, ['meta.carrier'])));

        static::assertSame(['carrier' => 'dhl'], $this->read('a')?->meta);
    }

    /**
     * A map field left at its default `[]` lacks the entry, so the stored row loses it, and keeps its other entries.
     */
    public function testAMapEntryTheEntityLacksIsRemovedFromTheStoredRow(): void
    {
        $stored = RecordEntity::create(self::TENANT, 'a');
        $stored->meta = ['carrier' => 'dhl', 'tracking' => 't-1'];
        $this->client()->put(new PutInput($stored));

        $this->client()->upsert(new UpsertInput(RecordEntity::create(self::TENANT, 'a'), ['meta.tracking']));

        static::assertSame(['carrier' => 'dhl'], $this->read('a')?->meta);
    }

    public function testAPathTheEntityHoldsNoValueForIsRemovedFromTheStoredRow(): void
    {
        $this->client()->put(new PutInput(RecordEntity::create(self::TENANT, 'a', name: 'stored')));

        $this->client()->upsert(new UpsertInput(RecordEntity::create(self::TENANT, 'a'), ['name']));

        static::assertNull($this->read('a')?->name);
    }

    /**
     * The update and the put bring the entity up to date as they do on their own.
     */
    public function testAnUpsertBringsItsEntityUpToDateWithWhatItStored(): void
    {
        $this->client()->put(new PutInput(RecordEntity::create(self::TENANT, 'a', name: 'stored', counter: 5)));

        $entity = RecordEntity::create(self::TENANT, 'a', RecordStatus::Done);
        $this->client()->upsert(new UpsertInput($entity, ['status']));

        static::assertSame('stored', $entity->name);
        static::assertSame(5, $entity->counter);
    }

    /**
     * Where no row is stored, the normalizer runs for the put it is, and otherwise for the update.
     */
    public function testAnUpsertIsNormalizedAsTheWriteItSends(): void
    {
        $entity = NormalizedEntity::create(self::TENANT, 'invoice');
        $entity->pk = self::TENANT . '#invoice';
        $entity->id = Uuid::v7();

        static::assertSame(UpsertOutcome::Created, $this->client()->upsert(new UpsertInput($entity, ['label'])));
        $created = $this->readNormalized($entity);

        $entity->label = 'renamed';
        static::assertSame(UpsertOutcome::Updated, $this->client()->upsert(new UpsertInput($entity, ['label'])));
        $updated = $this->readNormalized($entity);

        static::assertSame(1_700_000_000, $created?->createdAt->getTimestamp());
        static::assertNull($created->updatedAt);
        static::assertSame('renamed', $updated?->label);
        static::assertSame(1_700_000_000, $updated->createdAt->getTimestamp());
        static::assertSame(NormalizedEntityNormalizer::UPDATED_AT, $updated->updatedAt?->getTimestamp());
    }

    /**
     * The first write puts the counter at one, every later one adds one, and the condition stops it at three.
     * Where no row is stored, the condition is checked against a row without attributes, so `notExists()` holds.
     */
    public function testAnUpsertWithAnUpdateCountsUpToItsCondition(): void
    {
        $belowThree = Filter::or(Filter::notExists('counter'), Filter::lessThan('counter', 3));
        $upsert = fn (): UpsertInput => new UpsertInput(RecordEntity::create(self::TENANT, 'a', counter: 1), Update::increment('counter'), $belowThree);

        static::assertSame(UpsertOutcome::Created, $this->client()->upsert($upsert()));
        static::assertSame(UpsertOutcome::Updated, $this->client()->upsert($upsert()));
        static::assertSame(UpsertOutcome::Updated, $this->client()->upsert($upsert()));

        try {
            $this->client()->upsert($upsert());
            static::fail('The counter is at three');
        } catch (ConditionalCheckFailedException) {
        }

        static::assertSame(3, $this->read('a')?->counter);
    }

    /**
     * Both writes are prepared before either is sent, so an entity that cannot be put is refused even where the
     * update alone would do.
     */
    public function testAnUpsertOfAnEntityThatCannotBePutIsRefusedBeforeAnythingIsSent(): void
    {
        $this->client()->put(new PutInput(RecordEntity::create(self::TENANT, 'a', name: 'stored')));

        $entity = new RecordEntity();
        $entity->tenantId = self::TENANT;
        $entity->id = 'a';
        $entity->name = 'new';

        try {
            $this->client()->upsert(new UpsertInput($entity, ['name']));
            static::fail('The entity lacks `createdAt`');
        } catch (FieldMissingSerializedValueException $exception) {
            static::assertSame('createdAt', $exception->fieldDefinition->getName());
        }

        static::assertSame('stored', $this->read('a')?->name);
    }

    private function read(string $id): ?RecordEntity
    {
        $entity = $this->client()->get(new GetInput([new Key(RecordEntity::class, self::TENANT, $id)], consistentRead: true))->first();
        static::assertTrue($entity === null || $entity instanceof RecordEntity);

        /** @var ?RecordEntity $entity */
        return $entity;
    }

    private function readNormalized(NormalizedEntity $entity): ?NormalizedEntity
    {
        $read = $this->client()->get(new GetInput([new Key(NormalizedEntity::class, $entity->pk, $entity->id)], consistentRead: true))->first();
        static::assertTrue($read === null || $read instanceof NormalizedEntity);

        /** @var ?NormalizedEntity $read */
        return $read;
    }
}
