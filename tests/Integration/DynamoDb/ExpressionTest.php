<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Integration\DynamoDb;

use PHPUnit\Framework\Attributes\CoversClass;
use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Client\Cursor;
use Shopware\DynamodbDalBundle\Client\CursorHistory;
use Shopware\DynamodbDalBundle\Client\Index;
use Shopware\DynamodbDalBundle\Client\Input\DeleteInput;
use Shopware\DynamodbDalBundle\Client\Input\PutInput;
use Shopware\DynamodbDalBundle\Client\Input\QueryInput;
use Shopware\DynamodbDalBundle\Client\Input\ScanInput;
use Shopware\DynamodbDalBundle\Exception\InvalidCursorException;
use Shopware\DynamodbDalBundle\Expression\Contract\FilterInterface;
use Shopware\DynamodbDalBundle\Expression\ExpressionCompiler;
use Shopware\DynamodbDalBundle\Expression\Filter;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\Entity\RecordEntity;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\Entity\RecordStatus;
use AsyncAws\Core\Exception\Http\ClientException;

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

        $query = static fn (?string $cursor): QueryInput => new QueryInput(
            Filter::equals('status', RecordStatus::Open),
            index: 'statusIndex',
            cursor: $cursor,
            limit: 2,
        );

        $first = $this->client()->search(RecordEntity::class, $query(null))->page();
        static::assertCount(2, $first->items);
        static::assertNotNull($first->next);
        // Resuming an index query needs the index key as well as the table key.
        static::assertEqualsCanonicalizing(['tenantId', 'id', 'status', 'createdAt'], array_keys(Cursor::decode($first->next)->key));

        $second = $this->client()->search(RecordEntity::class, $query($first->next))->page();
        static::assertCount(1, $second->items);
        static::assertNull($second->next);

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

        $query = static fn (?string $cursor): QueryInput => new QueryInput(
            Filter::equals('tenantId', self::TENANT),
            filter: Filter::equals('name', 'match'),
            cursor: $cursor,
            limit: 2,
        );

        $first = $this->client()->search(RecordEntity::class, $query(null))->page();
        static::assertSame(['id-00', 'id-02'], $this->ids($first->items));
        static::assertNotNull($first->next);

        $second = $this->client()->search(RecordEntity::class, $query($first->next))->page();
        static::assertSame(['id-04'], $this->ids($second->items));
        static::assertNull($second->next);
    }

    public function testPagingBackRevisitsTheSamePagesWithoutAHistory(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->seed(RecordEntity::create(self::TENANT, \sprintf('id-%02d', $i)));
        }

        $query = static fn (?string $cursor): QueryInput => new QueryInput(
            Filter::equals('tenantId', self::TENANT),
            cursor: $cursor,
            limit: 2,
        );

        $first = $this->client()->search(RecordEntity::class, $query(null))->page();
        static::assertSame(['id-00', 'id-01'], $this->ids($first->items));
        static::assertNull($first->previous);

        $second = $this->client()->search(RecordEntity::class, $query($first->next))->page();
        $third = $this->client()->search(RecordEntity::class, $query($second->next))->page();
        static::assertSame(['id-04'], $this->ids($third->items));
        static::assertNull($third->next);

        $backToSecond = $this->client()->search(RecordEntity::class, $query($third->previous))->page();
        static::assertSame(['id-02', 'id-03'], $this->ids($backToSecond->items));
        static::assertNotNull($backToSecond->next);

        $backToFirst = $this->client()->search(RecordEntity::class, $query($backToSecond->previous))->page();
        static::assertSame(['id-00', 'id-01'], $this->ids($backToFirst->items));
        static::assertNull($backToFirst->previous);

        // Forward again from a page reached backwards lands where the forward walk did.
        $forwardAgain = $this->client()->search(RecordEntity::class, $query($backToFirst->next))->page();
        static::assertSame(['id-02', 'id-03'], $this->ids($forwardAgain->items));
    }

    public function testPagingBackKeepsADescendingFilteredIndexQueryInItsOrder(): void
    {
        for ($i = 1; $i <= 6; ++$i) {
            $this->seed(RecordEntity::create(
                self::TENANT,
                'id-' . $i,
                RecordStatus::Open,
                new \DateTimeImmutable('@' . ($i * 100)),
                name: $i === 3 ? 'skip' : 'match',
            ));
        }

        $query = static fn (?string $cursor): QueryInput => new QueryInput(
            Filter::equals('status', RecordStatus::Open),
            index: 'statusIndex',
            filter: Filter::equals('name', 'match'),
            forward: false,
            cursor: $cursor,
            limit: 2,
        );

        $first = $this->client()->search(RecordEntity::class, $query(null))->page();
        static::assertSame(['id-6', 'id-5'], $this->ids($first->items));

        $second = $this->client()->search(RecordEntity::class, $query($first->next))->page();
        static::assertSame(['id-4', 'id-2'], $this->ids($second->items));

        $back = $this->client()->search(RecordEntity::class, $query($second->previous))->page();
        static::assertSame(['id-6', 'id-5'], $this->ids($back->items));
        static::assertNull($back->previous);
    }

    /**
     * One query per status, merged newest first and cut to the page size. Each status resumes after its own
     * last item that made it onto the merged page, which is not what its own page's `next` points at.
     */
    public function testAPageMergedFromSeveralQueriesResumesEachAfterItsLastVisibleItem(): void
    {
        foreach ([600 => RecordStatus::Open, 500 => RecordStatus::Done, 400 => RecordStatus::Open, 300 => RecordStatus::Done, 200 => RecordStatus::Done, 100 => RecordStatus::Open] as $offset => $status) {
            $this->seed(RecordEntity::create(self::TENANT, 'id-' . $offset, $status, new \DateTimeImmutable('@' . $offset)));
        }

        $load = function (CursorHistory $history): array {
            $positions = CursorHistory::split($history->current());

            $pages = [];
            $items = [];
            foreach ([RecordStatus::Open, RecordStatus::Done] as $status) {
                $pages[$status->value] = $this->client()->search(RecordEntity::class, new QueryInput(
                    Filter::equals('status', $status),
                    index: 'statusIndex',
                    forward: false,
                    cursor: $positions[$status->value] ?? null,
                    limit: 2,
                ))->page();
                $items = [...$items, ...$pages[$status->value]->items];
            }

            usort($items, static function (AbstractEntity $a, AbstractEntity $b): int {
                static::assertInstanceOf(RecordEntity::class, $a);
                static::assertInstanceOf(RecordEntity::class, $b);

                return $b->createdAt <=> $a->createdAt;
            });
            $visible = \array_slice($items, 0, 2);

            $hasMore = \count($items) > 2;
            $next = $positions;
            foreach ($pages as $status => $page) {
                $hasMore = $hasMore || $page->next !== null;
                foreach ($visible as $item) {
                    if (\in_array($item, $page->items, true)) {
                        $next[$status] = $page->cursorAfter($item);
                    }
                }
            }

            return [$this->ids($visible), $hasMore ? $history->append(CursorHistory::combine($next)) : null];
        };

        [$first, $toSecond] = $load(new CursorHistory());
        static::assertSame(['id-600', 'id-500'], $first);
        static::assertNotNull($toSecond);

        // Through the URL and back, as a pager link carries it.
        [$second, $toThird] = $load(CursorHistory::fromString($toSecond->toString()));
        static::assertSame(['id-400', 'id-300'], $second);
        static::assertNotNull($toThird);

        [$third, $toFourth] = $load($toThird);
        static::assertSame(['id-200', 'id-100'], $third);
        static::assertNull($toFourth);

        $back = $toThird->previous();
        static::assertNotNull($back);
        static::assertSame(2, $back->page());
        static::assertSame(['id-400', 'id-300'], $load($back)[0]);
    }

    public function testAScanPagesBackThroughItsHistory(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->seed(RecordEntity::create(self::TENANT, \sprintf('id-%02d', $i)));
        }

        $load = fn (CursorHistory $history) => $this->client()->search(
            RecordEntity::class,
            new ScanInput(cursor: $history->current(), limit: 2),
        )->page();

        // Forward to the last page, remembering what each page showed.
        $history = new CursorHistory();
        $forward = [];
        while (true) {
            $page = $load($history);
            static::assertNull($page->previous, 'a scan cannot be read backward');
            $forward[$history->page()] = $this->ids($page->items);

            $next = $history->advance($page->next);
            if ($next === null) {
                break;
            }
            $history = $next;
        }

        static::assertSame(3, $history->page());
        static::assertEqualsCanonicalizing(['id-00', 'id-01', 'id-02', 'id-03', 'id-04'], array_merge(...$forward));

        // Back through the history, each page shows what it showed on the way forward.
        for ($back = $history->previous(); $back !== null; $back = $back->previous()) {
            static::assertSame($forward[$back->page()], $this->ids($load($back)->items));
        }
    }

    /**
     * Going back reads backward from the current page's first item, so rows deleted in the meantime are simply
     * not there: the previous page shrinks to what is left, and paging forward from it lands on the same page
     * as before.
     */
    public function testPagingBackOverDeletedRowsShowsWhatIsLeft(): void
    {
        for ($i = 0; $i < 4; ++$i) {
            $this->seed(RecordEntity::create(self::TENANT, \sprintf('id-%02d', $i)));
        }

        $query = static fn (?string $cursor): QueryInput => new QueryInput(Filter::equals('tenantId', self::TENANT), cursor: $cursor, limit: 2);
        $first = $this->client()->search(RecordEntity::class, $query(null))->page();
        $second = $this->client()->search(RecordEntity::class, $query($first->next))->page();
        static::assertSame(['id-02', 'id-03'], $this->ids($second->items));

        $this->client()->delete(RecordEntity::class, new DeleteInput(new Index(self::TENANT, 'id-01')));

        $back = $this->client()->search(RecordEntity::class, $query($second->previous))->page();
        static::assertSame(['id-00'], $this->ids($back->items));
        static::assertNull($back->previous, 'nothing is left before the first row');

        $forward = $this->client()->search(RecordEntity::class, $query($back->next))->page();
        static::assertSame(['id-02', 'id-03'], $this->ids($forward->items));
    }

    /**
     * With every row before the current page deleted, the backward read finds nothing. There is no item to cut
     * a token from, so the page offers neither direction: the listing has to be opened again from the start.
     */
    public function testPagingBackOverOnlyDeletedRowsComesBackEmptyWithoutTokens(): void
    {
        for ($i = 0; $i < 3; ++$i) {
            $this->seed(RecordEntity::create(self::TENANT, \sprintf('id-%02d', $i)));
        }

        $query = static fn (?string $cursor): QueryInput => new QueryInput(Filter::equals('tenantId', self::TENANT), cursor: $cursor, limit: 2);
        $first = $this->client()->search(RecordEntity::class, $query(null))->page();
        $second = $this->client()->search(RecordEntity::class, $query($first->next))->page();
        static::assertSame(['id-02'], $this->ids($second->items));

        foreach (['id-00', 'id-01'] as $id) {
            $this->client()->delete(RecordEntity::class, new DeleteInput(new Index(self::TENANT, $id)));
        }

        $back = $this->client()->search(RecordEntity::class, $query($second->previous))->page();
        static::assertSame([], $back->items);
        static::assertNull($back->next);
        static::assertNull($back->previous);
    }

    public function testATokenMintedOnTheTableIsRefusedByAnIndexQuery(): void
    {
        foreach (['a', 'b', 'c'] as $id) {
            $this->seed(RecordEntity::create(self::TENANT, $id, RecordStatus::Open, new \DateTimeImmutable('@100')));
        }

        $first = $this->client()->search(RecordEntity::class, new QueryInput(Filter::equals('tenantId', self::TENANT), limit: 1))->page();
        static::assertNotNull($first->next);

        // The token carries only the table key; resuming on the index would need the index key as well.
        $this->expectException(InvalidCursorException::class);
        $this->client()->search(RecordEntity::class, new QueryInput(
            Filter::equals('status', RecordStatus::Open),
            index: 'statusIndex',
            cursor: $first->next,
            limit: 1,
        ))->page();
    }

    /**
     * A token from another partition has exactly the attributes the query's key needs, so the bundle cannot tell
     * it does not belong there. DynamoDB refuses it as a start key, forward and backward alike, so a foreign token
     * never reads outside the query's partition.
     */
    public function testATokenFromAnotherPartitionIsRefused(): void
    {
        foreach ([self::TENANT, 'tenant-2'] as $tenant) {
            foreach (['a', 'b', 'c'] as $id) {
                $this->seed(RecordEntity::create($tenant, $id));
            }
        }

        $query = static fn (string $tenant, ?string $cursor): QueryInput => new QueryInput(Filter::equals('tenantId', $tenant), cursor: $cursor, limit: 1);
        $first = $this->client()->search(RecordEntity::class, $query(self::TENANT, null))->page();
        $second = $this->client()->search(RecordEntity::class, $query(self::TENANT, $first->next))->page();

        foreach (['next' => $first->next, 'previous' => $second->previous] as $direction => $token) {
            try {
                $this->client()->search(RecordEntity::class, $query('tenant-2', $token))->page();
                static::fail(\sprintf('The %s token of another partition must be refused.', $direction));
            } catch (ClientException) {
                // DynamoDB refused the start key
            }
        }
    }

    public function testATokenOutsideTheSortKeyConditionIsRefused(): void
    {
        for ($i = 0; $i < 6; ++$i) {
            $this->seed(RecordEntity::create(self::TENANT, \sprintf('id-%02d', $i)));
        }

        $first = $this->client()->search(RecordEntity::class, new QueryInput(Filter::equals('tenantId', self::TENANT), limit: 1))->page();

        // The token resumes after `id-00`, below the sort-key range the key condition asks for: DynamoDB refuses it.
        $this->expectException(ClientException::class);
        $this->client()->search(RecordEntity::class, new QueryInput(
            Filter::and(Filter::equals('tenantId', self::TENANT), Filter::between('id', 'id-03', 'id-05')),
            cursor: $first->next,
            limit: 2,
        ))->page();
    }

    public function testABackwardTokenIsRefusedByAScan(): void
    {
        foreach (['a', 'b', 'c'] as $id) {
            $this->seed(RecordEntity::create(self::TENANT, $id));
        }

        $query = static fn (?string $cursor): QueryInput => new QueryInput(Filter::equals('tenantId', self::TENANT), cursor: $cursor, limit: 1);
        $first = $this->client()->search(RecordEntity::class, $query(null))->page();
        $second = $this->client()->search(RecordEntity::class, $query($first->next))->page();
        static::assertNotNull($second->previous);

        // Same key attributes as the table a scan reads, but a scan has no order to reverse.
        $this->expectException(InvalidCursorException::class);
        $this->client()->search(RecordEntity::class, new ScanInput(cursor: $second->previous, limit: 1))->page();
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
        $this->client()->put(RecordEntity::class, new PutInput($entity));
    }

    /**
     * @return list<string>
     */
    private function scan(FilterInterface $filter): array
    {
        return $this->ids($this->client()->search(RecordEntity::class, new ScanInput($filter))->toArray(), sorted: true);
    }

    /**
     * @return list<string>
     */
    private function query(QueryInput $query, bool $sorted = true): array
    {
        return $this->ids($this->client()->search(RecordEntity::class, $query)->toArray(), $sorted);
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
