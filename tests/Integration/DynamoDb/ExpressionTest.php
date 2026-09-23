<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Integration\DynamoDb;

use PHPUnit\Framework\Attributes\CoversClass;
use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Client\Cursor\Cursor;
use Shopware\DynamodbDalBundle\Client\Input\PutInput;
use Shopware\DynamodbDalBundle\Client\Input\QueryInput;
use Shopware\DynamodbDalBundle\Client\Input\ScanInput;
use Shopware\DynamodbDalBundle\Expression\Contract\ExpressionInterface;
use Shopware\DynamodbDalBundle\Expression\ExpressionCompiler;
use Shopware\DynamodbDalBundle\Expression\Filter;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\Entity\RecordEntity;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\Entity\RecordStatus;

/**
 * The filter vocabulary and the index/pagination paths, asked of DynamoDB rather than of an assertion
 * about the expression string. A compiled expression that DynamoDB rejects, or silently reads
 * differently than intended, is exactly what a unit test over the string cannot catch — and it is the
 * shape repositories lean on hardest.
 */
#[CoversClass(ExpressionCompiler::class)]
#[CoversClass(Filter::class)]
class ExpressionTest extends DynamoDbTestCase
{
    public function testEqualsAnyMatchesEveryListedValue(): void
    {
        $this->seedStatuses();

        static::assertSame(
            ['done', 'failed'],
            $this->scan(Filter::equalsAny('status', [RecordStatus::Done, RecordStatus::Failed])),
        );
    }

    public function testNotEqualsAnyMatchesTheRest(): void
    {
        $this->seedStatuses();

        static::assertSame(['open'], $this->scan(Filter::notEqualsAny('status', [RecordStatus::Done, RecordStatus::Failed])));
    }

    public function testBetweenMatchesAnInclusiveRange(): void
    {
        foreach ([1, 5, 10, 20] as $counter) {
            $this->seed(RecordEntity::create(self::TENANT, (string) $counter, counter: $counter));
        }

        static::assertSame(['5', '10'], $this->scan(Filter::between('counter', 5, 10)));
    }

    public function testComparatorsMatchNumbers(): void
    {
        foreach ([1, 5, 10] as $counter) {
            $this->seed(RecordEntity::create(self::TENANT, (string) $counter, counter: $counter));
        }

        static::assertSame(['10'], $this->scan(Filter::greaterThan('counter', 5)));
        static::assertSame(['1'], $this->scan(Filter::lessThan('counter', 5)));
        static::assertSame(['5', '10'], $this->scan(Filter::greaterThanOrEquals('counter', 5)));
        static::assertSame(['1', '5'], $this->scan(Filter::lessThanOrEquals('counter', 5)));
    }

    public function testBeginsWithMatchesAPrefix(): void
    {
        $this->seed(RecordEntity::create(self::TENANT, 'a', name: 'alpha'));
        $this->seed(RecordEntity::create(self::TENANT, 'b', name: 'alpine'));
        $this->seed(RecordEntity::create(self::TENANT, 'c', name: 'beta'));

        static::assertSame(['a', 'b'], $this->scan(Filter::beginsWith('name', 'alp')));
    }

    public function testContainsMatchesASubstring(): void
    {
        $this->seed(RecordEntity::create(self::TENANT, 'a', name: 'needle in haystack'));
        $this->seed(RecordEntity::create(self::TENANT, 'b', name: 'nothing here'));

        static::assertSame(['a'], $this->scan(Filter::contains('name', 'needle')));
        static::assertSame(['b'], $this->scan(Filter::notContains('name', 'needle')));
    }

    /**
     * `contains(listAttribute, value)` compares one element, so the value is serialized with the
     * field's *value* serializer rather than the field's own.
     */
    public function testContainsMatchesOneElementOfAList(): void
    {
        $this->seed(RecordEntity::create(self::TENANT, 'a', tags: ['red', 'blue']));
        $this->seed(RecordEntity::create(self::TENANT, 'b', tags: ['green']));

        static::assertSame(['a'], $this->scan(Filter::contains('tags', 'blue')));
    }

    public function testExistsAndNotExistsSplitOnAnAbsentAttribute(): void
    {
        $deleted = RecordEntity::create(self::TENANT, 'a');
        $deleted->deletedAt = new \DateTimeImmutable('@1700000001');
        $this->seed($deleted);
        $this->seed(RecordEntity::create(self::TENANT, 'b'));

        static::assertSame(['a'], $this->scan(Filter::exists('deletedAt')));
        static::assertSame(['b'], $this->scan(Filter::notExists('deletedAt')));
    }

    public function testSizeEqualsMatchesAnEmptyCollection(): void
    {
        $this->seed(RecordEntity::create(self::TENANT, 'a', tags: []));
        $this->seed(RecordEntity::create(self::TENANT, 'b', tags: ['one', 'two']));

        static::assertSame(['a'], $this->scan(Filter::sizeEquals('tags', 0)));
        static::assertSame(['b'], $this->scan(Filter::sizeEquals('tags', 2)));
    }

    public function testNestedAndOrGroupsKeepTheirPrecedence(): void
    {
        $this->seed(RecordEntity::create(self::TENANT, 'a', RecordStatus::Open, name: 'keep', counter: 1));
        $this->seed(RecordEntity::create(self::TENANT, 'b', RecordStatus::Open, name: 'drop', counter: 9));
        $this->seed(RecordEntity::create(self::TENANT, 'c', RecordStatus::Done, name: 'keep', counter: 9));

        // status = open AND (name = keep OR counter = 9) — without the parentheses `b` would drop out
        // and `c` would come in.
        static::assertSame(['a', 'b'], $this->scan(Filter::and(
            Filter::equals('status', RecordStatus::Open),
            Filter::or(
                Filter::equals('name', 'keep'),
                Filter::equals('counter', 9),
            ),
        )));
    }

    public function testNotOfACompoundNegatesTheWholeGroup(): void
    {
        $this->seed(RecordEntity::create(self::TENANT, 'a', name: 'keep', counter: 1));
        $this->seed(RecordEntity::create(self::TENANT, 'b', name: 'drop', counter: 1));
        $this->seed(RecordEntity::create(self::TENANT, 'c', name: 'drop', counter: 9));

        static::assertSame(['c'], $this->scan(Filter::not(Filter::or(
            Filter::equals('name', 'keep'),
            Filter::equals('counter', 1),
        ))));
    }

    public function testNestedPathMatchesOneMapEntry(): void
    {
        $matching = RecordEntity::create(self::TENANT, 'a');
        $matching->meta = ['kind' => 'invoice'];
        $this->seed($matching);

        $other = RecordEntity::create(self::TENANT, 'b');
        $other->meta = ['kind' => 'receipt'];
        $this->seed($other);

        static::assertSame(['a'], $this->scan(Filter::equals('meta.kind', 'invoice')));
    }

    public function testQueryOnAGlobalSecondaryIndex(): void
    {
        $this->seed(RecordEntity::create(self::TENANT, 'a', RecordStatus::Open, new \DateTimeImmutable('@100')));
        $this->seed(RecordEntity::create(self::TENANT, 'b', RecordStatus::Open, new \DateTimeImmutable('@200')));
        $this->seed(RecordEntity::create('tenant-2', 'c', RecordStatus::Open, new \DateTimeImmutable('@300')));
        $this->seed(RecordEntity::create(self::TENANT, 'd', RecordStatus::Done, new \DateTimeImmutable('@400')));

        $found = $this->query(new QueryInput(Filter::equals('status', RecordStatus::Open), index: 'statusIndex'));

        // The index partitions by status, not by tenant, so it crosses the base table's partitions.
        static::assertSame(['a', 'b', 'c'], $found);
    }

    public function testQueryOnAnIndexOrdersByItsSortKey(): void
    {
        $this->seed(RecordEntity::create(self::TENANT, 'a', RecordStatus::Open, new \DateTimeImmutable('@100')));
        $this->seed(RecordEntity::create(self::TENANT, 'b', RecordStatus::Open, new \DateTimeImmutable('@200')));
        $this->seed(RecordEntity::create(self::TENANT, 'c', RecordStatus::Open, new \DateTimeImmutable('@300')));

        static::assertSame(['c', 'b', 'a'], $this->query(new QueryInput(
            Filter::equals('status', RecordStatus::Open),
            index: 'statusIndex',
            forward: false,
        ), sorted: false));
    }

    public function testQueryOnAnIndexNarrowsBySortKeyRange(): void
    {
        $this->seed(RecordEntity::create(self::TENANT, 'a', RecordStatus::Open, new \DateTimeImmutable('@100')));
        $this->seed(RecordEntity::create(self::TENANT, 'b', RecordStatus::Open, new \DateTimeImmutable('@200')));
        $this->seed(RecordEntity::create(self::TENANT, 'c', RecordStatus::Open, new \DateTimeImmutable('@300')));

        static::assertSame(['b', 'c'], $this->query(new QueryInput(
            Filter::and(
                Filter::equals('status', RecordStatus::Open),
                Filter::greaterThanOrEquals('createdAt', new \DateTimeImmutable('@200')),
            ),
            index: 'statusIndex',
        )));
    }

    public function testPagingAnIndexQueryCarriesBothKeysInTheCursor(): void
    {
        foreach ([100, 200, 300] as $offset) {
            $this->seed(RecordEntity::create(self::TENANT, 'id-' . $offset, RecordStatus::Open, new \DateTimeImmutable('@' . $offset)));
        }

        $query = static fn (?Cursor $cursor): QueryInput => new QueryInput(
            Filter::equals('status', RecordStatus::Open),
            index: 'statusIndex',
            cursor: $cursor,
            limit: 2,
        );

        $first = $this->client()->search($this->definition('record'), $query(null))->page();
        static::assertCount(2, $first->items);
        static::assertNotNull($first->nextCursor);
        // Resuming an index query needs the index key as well as the primary key.
        static::assertSame('statusIndex', $first->nextCursor->indexKey?->index);

        $second = $this->client()->search($this->definition('record'), $query($first->nextCursor))->page();
        static::assertCount(1, $second->items);
        static::assertNull($second->nextCursor);

        static::assertSame(['id-100', 'id-200', 'id-300'], $this->ids([...$first->items, ...$second->items], sorted: true));
    }

    /**
     * DynamoDB's `Limit` counts rows read, not rows matched, so the reader only sends it when there is
     * no filter to thin the page out. With a filter, the page is cut to size on this side — which has
     * to yield a full page and a working cursor all the same.
     */
    public function testALimitedQueryWithAFilterStillFillsThePage(): void
    {
        for ($i = 0; $i < 6; ++$i) {
            // Every other row fails the filter, so a server-side Limit of 3 would return one match.
            $this->seed(RecordEntity::create(
                self::TENANT,
                \sprintf('id-%02d', $i),
                name: $i % 2 === 0 ? 'match' : 'skip',
            ));
        }

        $query = static fn (?Cursor $cursor): QueryInput => new QueryInput(
            Filter::equals('tenantId', self::TENANT),
            filter: Filter::equals('name', 'match'),
            cursor: $cursor,
            limit: 2,
        );

        $first = $this->client()->search($this->definition('record'), $query(null))->page();
        static::assertSame(['id-00', 'id-02'], $this->ids($first->items));
        static::assertNotNull($first->nextCursor);

        $second = $this->client()->search($this->definition('record'), $query($first->nextCursor))->page();
        static::assertSame(['id-04'], $this->ids($second->items));
        static::assertNull($second->nextCursor);
    }

    public function testQueryCombinesAKeyConditionWithAFilter(): void
    {
        $this->seed(RecordEntity::create(self::TENANT, 'a', name: 'match'));
        $this->seed(RecordEntity::create(self::TENANT, 'b', name: 'other'));
        $this->seed(RecordEntity::create('tenant-2', 'c', name: 'match'));

        static::assertSame(['a'], $this->query(new QueryInput(
            Filter::equals('tenantId', self::TENANT),
            filter: Filter::equals('name', 'match'),
        )));
    }

    private function seedStatuses(): void
    {
        $this->seed(RecordEntity::create(self::TENANT, 'open', RecordStatus::Open));
        $this->seed(RecordEntity::create(self::TENANT, 'done', RecordStatus::Done));
        $this->seed(RecordEntity::create(self::TENANT, 'failed', RecordStatus::Failed));
    }

    private function seed(RecordEntity $entity): void
    {
        $this->client()->put($this->definition('record'), new PutInput($entity));
    }

    /**
     * @return list<string>
     */
    private function scan(ExpressionInterface $filter): array
    {
        return $this->ids($this->client()->search($this->definition('record'), new ScanInput($filter))->toArray(), sorted: true);
    }

    /**
     * @return list<string>
     */
    private function query(QueryInput $query, bool $sorted = true): array
    {
        return $this->ids($this->client()->search($this->definition('record'), $query)->toArray(), $sorted);
    }

    /**
     * @param array<AbstractEntity> $entities
     *
     * @return list<string>
     */
    private function ids(array $entities, bool $sorted = false): array
    {
        $ids = [];
        foreach ($entities as $entity) {
            static::assertInstanceOf(RecordEntity::class, $entity);
            $ids[] = $entity->id;
        }

        if ($sorted) {
            sort($ids);
        }

        return $ids;
    }
}
