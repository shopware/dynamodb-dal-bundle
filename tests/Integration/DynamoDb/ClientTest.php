<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Integration\DynamoDb;

use PHPUnit\Framework\Attributes\CoversClass;
use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Client\Client;
use Shopware\DynamodbDalBundle\Client\Key;
use Shopware\DynamodbDalBundle\Client\Input\DeleteInput;
use Shopware\DynamodbDalBundle\Client\Input\GetInput;
use Shopware\DynamodbDalBundle\Client\Input\PutInput;
use Shopware\DynamodbDalBundle\Client\Input\QueryInput;
use Shopware\DynamodbDalBundle\Client\Input\ScanInput;
use Shopware\DynamodbDalBundle\Expression\Filter;
use Shopware\DynamodbDalBundle\Exception\UnknownEntityDefinitionException;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\Entity\ArchiveEntity;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\Entity\RecordEntity;

/**
 * Drives the {@see Client} facade against real tables: key reads by entity class through a
 * {@see GetInput}, searches and writes by their {@see \Shopware\DynamodbDalBundle\Definition\EntityDefinition}.
 */
#[CoversClass(Client::class)]
class ClientTest extends DynamoDbTestCase
{
    public function testUpsertThenGetRoundTripsAnEntity(): void
    {
        $entity = RecordEntity::create(self::TENANT, 'a', name: 'first', counter: 7, tags: ['x', 'y']);
        $entity->meta = ['k' => 'v'];
        $entity->groups = ['g' => ['one', 'two']];
        $entity->payload = ['nested' => ['deep' => true]];
        $entity->amount = 19.99;
        $entity->active = false;

        $this->client()->put(new PutInput($entity));

        $read = $this->client()->get(new GetInput([new Key(RecordEntity::class, self::TENANT, 'a')]))->first();

        static::assertInstanceOf(RecordEntity::class, $read);
        static::assertEquals($entity->getVars(), $read->getVars());
    }

    public function testUpsertOverwritesAnExistingItem(): void
    {
        $this->client()->put(new PutInput(RecordEntity::create(self::TENANT, 'a', name: 'before')));
        $this->client()->put(new PutInput(RecordEntity::create(self::TENANT, 'a', name: 'after')));

        $read = $this->client()->get(new GetInput([new Key(RecordEntity::class, self::TENANT, 'a')]))->first();

        static::assertInstanceOf(RecordEntity::class, $read);
        static::assertSame('after', $read->name);
        static::assertSame(1, $this->client()->count(new ScanInput(RecordEntity::class)));
    }

    public function testGetMissingKeyReturnsNull(): void
    {
        static::assertNull($this->client()->get(new GetInput([new Key(RecordEntity::class, self::TENANT, 'nope')]))->first());
    }

    public function testGetSeveralKeysReadsViaBatchGetItem(): void
    {
        foreach (['a', 'b', 'c'] as $id) {
            $this->client()->put(new PutInput(RecordEntity::create(self::TENANT, $id)));
        }

        $output = $this->client()->get(new GetInput([new Key(RecordEntity::class, self::TENANT, 'a'),
            new Key(RecordEntity::class, self::TENANT, 'c')]));

        static::assertSame(['a', 'c'], $this->sortedIds($output->toArray()));
    }

    public function testGetReadsKeysSpanningSeveralTables(): void
    {
        $this->client()->put(new PutInput(RecordEntity::create(self::TENANT, 'a')));
        $this->client()->put(new PutInput(ArchiveEntity::create('arch-1', 'kept')));

        $output = $this->client()->get(new GetInput([new Key(RecordEntity::class, self::TENANT, 'a')])
            ->withKey(new Key(ArchiveEntity::class, 'arch-1')));

        $grouped = $output->grouped();

        static::assertCount(1, $grouped[RecordEntity::class] ?? []);
        $archived = $grouped[ArchiveEntity::class][0] ?? null;
        static::assertCount(1, $grouped[ArchiveEntity::class] ?? []);
        static::assertInstanceOf(ArchiveEntity::class, $archived);
        static::assertSame('kept', $archived->label);
    }

    public function testGetThrowsForAnUnregisteredEntityClass(): void
    {
        static::expectException(UnknownEntityDefinitionException::class);

        /** @phpstan-ignore-next-line argument.type -- deliberately not an entity of this application */
        $this->client()->get(new GetInput([new Key(\stdClass::class, 'x')]))->first();
    }

    public function testGetWithConsistentReadSucceeds(): void
    {
        $this->client()->put(new PutInput(RecordEntity::create(self::TENANT, 'a', name: 'strong')));

        $read = $this->client()
            ->get(new GetInput([new Key(RecordEntity::class, self::TENANT, 'a')], true))
            ->first();

        static::assertInstanceOf(RecordEntity::class, $read);
        static::assertSame('strong', $read->name);
    }

    public function testSearchReturnsEmptyWhenNoMatch(): void
    {
        $output = $this->client()->search(new ScanInput(RecordEntity::class, Filter::equals('name', 'absent')));

        static::assertSame([], $output->toArray());
    }

    public function testCountReturnsZeroWhenNoMatch(): void
    {
        static::assertSame(0, $this->client()->count(new ScanInput(RecordEntity::class, Filter::equals('name', 'absent'))));
    }

    public function testSearchToArrayReturnsAllMatches(): void
    {
        foreach (['a', 'b', 'c'] as $id) {
            $this->client()->put(new PutInput(RecordEntity::create(self::TENANT, $id)));
        }

        static::assertSame(['a', 'b', 'c'], $this->sortedIds($this->client()->search(new ScanInput(RecordEntity::class))->toArray()));
    }

    public function testSearchToArrayStopsAtTheLimit(): void
    {
        foreach (['a', 'b', 'c', 'd'] as $id) {
            $this->client()->put(new PutInput(RecordEntity::create(self::TENANT, $id, name: $id === 'a' ? null : 'match')));
        }

        $query = new QueryInput(RecordEntity::class, Filter::equals('tenantId', self::TENANT), limit: 2);
        static::assertSame(['a', 'b'], $this->sortedIds($this->client()->search($query)->toArray()));

        // A filter leaves DynamoDB's `Limit` unset, so the stream itself has to stop.
        $filtered = new QueryInput(RecordEntity::class, Filter::equals('tenantId', self::TENANT), filter: Filter::equals('name', 'match'), limit: 2);
        static::assertSame(['b', 'c'], $this->sortedIds($this->client()->search($filtered)->toArray()));
    }

    public function testCountReturnsServerSideTotal(): void
    {
        foreach (['a', 'b', 'c'] as $id) {
            $this->client()->put(new PutInput(RecordEntity::create(self::TENANT, $id, name: $id === 'b' ? 'match' : null)));
        }

        static::assertSame(3, $this->client()->count(new ScanInput(RecordEntity::class)));
        static::assertSame(1, $this->client()->count(new ScanInput(RecordEntity::class, Filter::equals('name', 'match'))));
    }

    public function testPageWithNoLimitReturnsAllItemsAndNoNextToken(): void
    {
        foreach (['a', 'b'] as $id) {
            $this->client()->put(new PutInput(RecordEntity::create(self::TENANT, $id)));
        }

        $page = $this->client()->search(new ScanInput(RecordEntity::class))->page();

        static::assertCount(2, $page->items);
        static::assertNull($page->next);
    }

    public function testPageReturnsNoNextTokenWhenResultsFitOnePage(): void
    {
        foreach (['a', 'b'] as $id) {
            $this->client()->put(new PutInput(RecordEntity::create(self::TENANT, $id)));
        }

        $page = $this->client()->search(new QueryInput(RecordEntity::class, Filter::equals('tenantId', self::TENANT), limit: 5))->page();

        static::assertCount(2, $page->items);
        static::assertNull($page->next);
    }

    public function testPageResumesAcrossATokenWithoutSkippingOrRepeating(): void
    {
        foreach (['a', 'b', 'c'] as $id) {
            $this->client()->put(new PutInput(RecordEntity::create(self::TENANT, $id)));
        }

        $query = static fn (?string $cursor): QueryInput => new QueryInput(
            RecordEntity::class,
            Filter::equals('tenantId', self::TENANT),
            cursor: $cursor,
            limit: 2,
        );

        $first = $this->client()->search($query(null))->page();
        static::assertCount(2, $first->items);
        static::assertNotNull($first->next);

        $second = $this->client()->search($query($first->next))->page();
        static::assertCount(1, $second->items);
        static::assertNull($second->next);

        static::assertSame(['a', 'b', 'c'], $this->sortedIds([...$first->items, ...$second->items]));
    }

    public function testDeleteByIndexIsIdempotent(): void
    {
        $this->client()->put(new PutInput(RecordEntity::create(self::TENANT, 'a')));

        $this->client()->delete(new DeleteInput(new Key(RecordEntity::class, self::TENANT, 'a')));
        $this->client()->delete(new DeleteInput(new Key(RecordEntity::class, self::TENANT, 'a')));

        static::assertSame(0, $this->client()->count(new ScanInput(RecordEntity::class)));
    }

    public function testDeleteByEntityReadsTheKeyOffTheEntity(): void
    {
        $entity = RecordEntity::create(self::TENANT, 'a');
        $this->client()->put(new PutInput($entity));

        $this->client()->delete(new DeleteInput($entity));

        static::assertSame(0, $this->client()->count(new ScanInput(RecordEntity::class)));
    }

    /**
     * @param array<AbstractEntity> $entities
     *
     * @return list<string>
     */
    private function sortedIds(array $entities): array
    {
        $ids = [];
        foreach ($entities as $entity) {
            static::assertInstanceOf(RecordEntity::class, $entity);
            $ids[] = $entity->id;
        }

        sort($ids);

        return $ids;
    }
}
