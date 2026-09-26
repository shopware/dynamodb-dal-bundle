<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Client;

use Shopware\DynamodbDalBundle\Client\Cursor;
use Shopware\DynamodbDalBundle\Client\Input\QueryInput;
use Shopware\DynamodbDalBundle\Client\Input\ScanInput;
use Shopware\DynamodbDalBundle\Client\Output\SearchOutput;
use Shopware\DynamodbDalBundle\Exception\InvalidCursorException;
use Shopware\DynamodbDalBundle\Expression\Filter;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\NormalEntity;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SearchOutput::class)]
class SearchOutputTest extends TestCase
{
    public function testStreamsEveryMatchFromTheSourceGenerator(): void
    {
        [$a, $b] = self::entities('a', 'b');

        $result = $this->searchOutput(self::stream($a, $b));

        static::assertSame([$a, $b], $result->toArray());
    }

    public function testIteratesEveryMatchKeyedByPositionNotByRawKey(): void
    {
        [$a, $b] = self::entities('a', 'b');

        $result = $this->searchOutput(self::stream($a, $b));

        static::assertSame([0 => $a, 1 => $b], iterator_to_array($result));
    }

    public function testThrowsWhenConsumedASecondTime(): void
    {
        [$a] = self::entities('a');

        $result = $this->searchOutput(self::stream($a));

        static::assertSame([$a], $result->toArray());

        // A second terminal on the same output is a programming error — the stream is consumed once.
        $this->expectException(\LogicException::class);
        $result->first();
    }

    public function testFirstIsLazyAndDoesNotPullPastTheFirstMatch(): void
    {
        [$a, $b] = self::entities('a', 'b');

        // An ArrayObject holder records how far the source generator advances (its runtime mutation is
        // opaque to static analysis, unlike a by-ref variable which phpstan would constant-fold).
        /** @var \ArrayObject<int, string> $pulled */
        $pulled = new \ArrayObject();

        $result = $this->searchOutput((static function () use ($a, $b, $pulled): \Generator {
            $pulled->append('a');
            yield self::key('a') => $a;
            $pulled->append('b');
            yield self::key('b') => $b;
        })());

        static::assertSame($a, $result->first());
        static::assertSame(['a'], $pulled->getArrayCopy(), 'first() must not pull past the first match');
    }

    public function testStreamingStopsAtTheLimitWithoutPullingFurther(): void
    {
        [$a, $b, $c] = self::entities('a', 'b', 'c');

        /** @var \ArrayObject<int, string> $reached */
        $reached = new \ArrayObject();

        $result = $this->searchOutput((static function () use ($a, $b, $c, $reached): \Generator {
            yield self::key('a') => $a;
            yield self::key('b') => $b;
            $reached->append('c');
            yield self::key('c') => $c;
        })(), new ScanInput(NormalEntity::class, limit: 2));

        static::assertSame([$a, $b], $result->toArray());
        static::assertCount(0, $reached, 'unlike page(), a stream has no next page to peek for');
    }

    public function testEmptyResultHasNoFirst(): void
    {
        $result = $this->searchOutput(self::stream());

        static::assertNull($result->first());
    }

    public function testPageReturnsExactlyTheLimitWithANextTokenWhenMoreExist(): void
    {
        [$a, $b, $c] = self::entities('a', 'b', 'c');

        // Holder records whether the third item is ever pulled; page() over a limit of 1 takes 1 + peeks 1
        // to learn a next page exists, so it must stop before reaching `c`.
        /** @var \ArrayObject<int, string> $reached */
        $reached = new \ArrayObject();

        $result = $this->searchOutput((static function () use ($a, $b, $c, $reached): \Generator {
            yield self::key('a') => $a;
            yield self::key('b') => $b;
            $reached->append('c');
            yield self::key('c') => $c;
        })(), new ScanInput(NormalEntity::class, limit: 1));

        $page = $result->page();

        // The token resumes after the last VISIBLE entity (a), not the peeked one (b).
        static::assertSame([$a], $page->items);
        static::assertEquals(new Cursor(self::key('a')), self::decode($page->next));
        static::assertNull($page->previous);
        static::assertCount(0, $reached, 'page() must not pull past the over-fetched boundary item');
    }

    public function testPageCountsALimitBelowOneAsOne(): void
    {
        [$a, $b] = self::entities('a', 'b');

        $page = $this->searchOutput(self::stream($a, $b), new ScanInput(NormalEntity::class, limit: 0))->page();

        static::assertSame([$a], $page->items);
        static::assertEquals(new Cursor(self::key('a')), self::decode($page->next));
    }

    public function testPageReturnsAllWithoutTokensWhenTheyFitTheLimit(): void
    {
        [$a, $b] = self::entities('a', 'b');

        $page = $this->searchOutput(self::stream($a, $b), new ScanInput(NormalEntity::class, limit: 5))->page();

        static::assertSame([$a, $b], $page->items);
        static::assertNull($page->next);
        static::assertNull($page->previous);
    }

    public function testPageWithoutALimitReturnsEveryMatchAndNoNextToken(): void
    {
        [$a, $b] = self::entities('a', 'b');

        $page = $this->searchOutput(self::stream($a, $b))->page();

        static::assertSame([$a, $b], $page->items);
        static::assertNull($page->next);
    }

    public function testAResumedQueryPageLinksBackFromItsFirstItem(): void
    {
        [$b, $c, $d] = self::entities('b', 'c', 'd');

        $query = self::query(new Cursor(self::key('a')), limit: 2);
        $page = $this->searchOutput(self::stream($b, $c, $d), $query)->page();

        static::assertSame([$b, $c], $page->items);
        static::assertEquals(new Cursor(self::key('c')), self::decode($page->next));
        static::assertEquals(new Cursor(self::key('b'), backward: true), self::decode($page->previous));
    }

    public function testAResumedScanPageNeverLinksBack(): void
    {
        [$b] = self::entities('b');

        $scan = new ScanInput(NormalEntity::class, cursor: new Cursor(self::key('a'))->encode(), limit: 1);
        $page = $this->searchOutput(self::stream($b), $scan)->page();

        static::assertNull($page->previous);
    }

    public function testABackwardPageRestoresTheQueryOrder(): void
    {
        [$a, $b, $c] = self::entities('a', 'b', 'c');

        // Reading back from `d` streams towards the start: c, b, then a is the over-fetched peek.
        $query = self::query(new Cursor(self::key('d'), backward: true), limit: 2);
        $page = $this->searchOutput(self::stream($c, $b, $a), $query)->page();

        static::assertSame([$b, $c], $page->items);
        static::assertEquals(new Cursor(self::key('c')), self::decode($page->next));
        static::assertEquals(new Cursor(self::key('b'), backward: true), self::decode($page->previous));
    }

    public function testABackwardPageThatReachesTheStartHasNoPreviousToken(): void
    {
        [$a, $b] = self::entities('a', 'b');

        $query = self::query(new Cursor(self::key('c'), backward: true), limit: 2);
        $page = $this->searchOutput(self::stream($b, $a), $query)->page();

        static::assertSame([$a, $b], $page->items);
        static::assertEquals(new Cursor(self::key('b')), self::decode($page->next));
        static::assertNull($page->previous);
    }

    public function testABackwardPageThatFindsNothingOffersNoTokens(): void
    {
        // Every row before `b` was deleted since the page starting at `b` was read.
        $query = self::query(new Cursor(self::key('b'), backward: true), limit: 2);
        $page = $this->searchOutput(self::stream(), $query)->page();

        static::assertSame([], $page->items);
        static::assertNull($page->next);
        static::assertNull($page->previous);
    }

    public function testItemTokensStayWithTheirItemsWhenABackwardPageIsReversed(): void
    {
        [$a, $b, $c] = self::entities('a', 'b', 'c');

        $query = self::query(new Cursor(self::key('d'), backward: true), limit: 3);
        $page = $this->searchOutput(self::stream($c, $b, $a), $query)->page();

        static::assertSame([$a, $b, $c], $page->items);
        static::assertEquals(new Cursor(self::key('b')), Cursor::decode($page->cursorAfter($b)));
        static::assertEquals(new Cursor(self::key('a'), backward: true), Cursor::decode($page->cursorBefore($a)));
    }

    public function testAScanPageOffersNoPreviousButMintsItemTokensEitherWay(): void
    {
        [$a, $b] = self::entities('a', 'b');

        $scan = new ScanInput(NormalEntity::class, cursor: new Cursor(self::key('z'))->encode(), limit: 5);
        $page = $this->searchOutput(self::stream($a, $b), $scan)->page();

        // The caller need not know it paged a scan: a backward token is still made, and the reader refuses it.
        static::assertNull($page->previous);
        static::assertEquals(new Cursor(self::key('a')), Cursor::decode($page->cursorAfter($a)));
        static::assertEquals(new Cursor(self::key('a'), backward: true), Cursor::decode($page->cursorBefore($a)));
    }

    public function testASourceKeyedByPositionStreamsAndPagesWithoutNeedingATokenStill(): void
    {
        [$a, $b] = self::entities('a', 'b');

        // A hand-built source (a test double) yields plain `yield $entity`, so no start keys come along.
        $unkeyed = static fn (): \Generator => yield from [$a, $b];

        static::assertSame([$a, $b], $this->searchOutput($unkeyed())->toArray());
        static::assertSame([$a, $b], $this->searchOutput($unkeyed(), new ScanInput(NormalEntity::class, limit: 5))->page()->items);
    }

    public function testATokenFromASourceKeyedByPositionIsRefusedWhenUsed(): void
    {
        [$a, $b] = self::entities('a', 'b');

        $page = $this->searchOutput((static fn (): \Generator => yield from [$a, $b])(), new ScanInput(NormalEntity::class, limit: 1))->page();

        static::assertSame([$a], $page->items);
        static::assertNotNull($page->next);

        $this->expectException(InvalidCursorException::class);
        Cursor::decode($page->next);
    }

    public function testPageRefusesATokenItDidNotProduce(): void
    {
        [$a] = self::entities('a');

        $this->expectException(InvalidCursorException::class);
        $this->searchOutput(self::stream($a), new ScanInput(NormalEntity::class, cursor: 'not-a-token', limit: 1))->page();
    }

    public function testPageConsumesTheOutput(): void
    {
        [$a, $b] = self::entities('a', 'b');

        $result = $this->searchOutput(self::stream($a, $b), new ScanInput(NormalEntity::class, limit: 1));

        $result->page();

        // page() consumes the single-use output; reading it again is a programming error. The next
        // page is a fresh search carrying the returned token, not a second call on this output.
        $this->expectException(\LogicException::class);
        $result->toArray();
    }

    /**
     * @param \Generator<array<string, AttributeValue>|int, NormalEntity> $source
     * @param ScanInput<NormalEntity>|QueryInput<NormalEntity> $search
     *
     * @return SearchOutput<NormalEntity>
     */
    private function searchOutput(\Generator $source, ScanInput|QueryInput $search = new ScanInput(NormalEntity::class)): SearchOutput
    {
        return new SearchOutput($source, $search);
    }

    /**
     * @return QueryInput<NormalEntity>
     */
    private static function query(Cursor $cursor, int $limit): QueryInput
    {
        return new QueryInput(NormalEntity::class, Filter::equals('autofilledId', 'x'), cursor: $cursor->encode(), limit: $limit);
    }

    /**
     * @return list<NormalEntity>
     */
    private static function entities(string ...$ids): array
    {
        return array_values(array_map(static fn (string $id): NormalEntity => new NormalEntity()->setAutofilledId($id)->setRequired('req'), $ids));
    }

    /**
     * @return \Generator<array<string, AttributeValue>, NormalEntity>
     */
    private static function stream(NormalEntity ...$entities): \Generator
    {
        foreach ($entities as $entity) {
            yield self::key($entity->getAutofilledId()) => $entity;
        }
    }

    /**
     * @return array<string, AttributeValue>
     */
    private static function key(string $id): array
    {
        return ['autofilledId' => new AttributeValue(['S' => $id])];
    }

    private static function decode(?string $token): Cursor
    {
        static::assertNotNull($token);

        return Cursor::decode($token);
    }
}
