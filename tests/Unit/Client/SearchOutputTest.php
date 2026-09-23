<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Client;

use Shopware\DynamodbDalBundle\Client\Cursor\Cursor;
use Shopware\DynamodbDalBundle\Client\Input\ScanInput;
use Shopware\DynamodbDalBundle\Client\Output\SearchOutput;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\NormalEntity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SearchOutput::class)]
class SearchOutputTest extends TestCase
{
    /**
     * @var EntityDefinition<NormalEntity>
     */
    private EntityDefinition $definition;

    protected function setUp(): void
    {
        $this->definition = NormalEntity::createDefinition();
    }

    public function testStreamsEveryMatchFromTheSourceGenerator(): void
    {
        $a = new NormalEntity()->setAutofilledId('a')->setRequired('req');
        $b = new NormalEntity()->setAutofilledId('b')->setRequired('req');

        $result = $this->queryOutput((static function () use ($a, $b): \Generator {
            yield $a;
            yield $b;
        })());

        static::assertSame([$a, $b], $result->toArray());
    }

    public function testIteratesEveryMatchFromTheSourceGenerator(): void
    {
        $a = new NormalEntity()->setAutofilledId('a')->setRequired('req');
        $b = new NormalEntity()->setAutofilledId('b')->setRequired('req');

        $result = $this->queryOutput((static function () use ($a, $b): \Generator {
            yield $a;
            yield $b;
        })());

        static::assertSame([$a, $b], iterator_to_array($result, false));
    }

    public function testThrowsWhenConsumedASecondTime(): void
    {
        $a = new NormalEntity()->setAutofilledId('a')->setRequired('req');

        $result = $this->queryOutput((static function () use ($a): \Generator {
            yield $a;
        })());

        static::assertSame([$a], $result->toArray());

        // A second terminal on the same output is a programming error — the stream is consumed once.
        $this->expectException(\LogicException::class);
        $result->first();
    }

    public function testFirstIsLazyAndDoesNotPullPastTheFirstMatch(): void
    {
        $a = new NormalEntity()->setAutofilledId('a')->setRequired('req');
        $b = new NormalEntity()->setAutofilledId('b')->setRequired('req');

        // An ArrayObject holder records how far the source generator advances (its runtime mutation is
        // opaque to static analysis, unlike a by-ref variable which phpstan would constant-fold).
        /** @var \ArrayObject<int, string> $pulled */
        $pulled = new \ArrayObject();

        $result = $this->queryOutput((static function () use ($a, $b, $pulled): \Generator {
            $pulled->append('a');
            yield $a;
            $pulled->append('b');
            yield $b;
        })());

        static::assertSame($a, $result->first());
        static::assertSame(['a'], $pulled->getArrayCopy(), 'first() must not pull past the first match');
    }

    public function testEmptyResultHasNoFirst(): void
    {
        $result = $this->queryOutput((static function (): \Generator {
            yield from [];
        })());

        static::assertNull($result->first());
    }

    public function testPageReturnsExactlyTheLimitWithCursorWhenMoreExist(): void
    {
        $a = new NormalEntity()->setAutofilledId('a')->setRequired('req');
        $b = new NormalEntity()->setAutofilledId('b')->setRequired('req');
        $c = new NormalEntity()->setAutofilledId('c')->setRequired('req');

        // Holder records whether the third item is ever pulled; page() over a limit of 1 takes 1 + peeks 1
        // to learn a next page exists, so it must stop before reaching `c`.
        /** @var \ArrayObject<int, string> $reached */
        $reached = new \ArrayObject();

        $result = $this->queryOutput((static function () use ($a, $b, $c, $reached): \Generator {
            yield $a;
            yield $b;
            $reached->append('c');
            yield $c;
        })(), 1);

        $page = $result->page();

        // The cursor is built from the last VISIBLE entity (a), not the peeked one (b). The ScanInput has
        // no index, so the cursor carries only the base-table primary key (autofilledId), no index key.
        static::assertSame([$a], $page->items);
        static::assertInstanceOf(Cursor::class, $page->nextCursor);
        static::assertSame('normal', $page->nextCursor->table);
        static::assertSame('a', $page->nextCursor->primaryKey->hashValue);
        static::assertNull($page->nextCursor->indexKey);
        static::assertCount(0, $reached, 'page() must not pull past the over-fetched boundary item');
    }

    public function testPageReturnsAllWithNullCursorWhenTheyFitTheLimit(): void
    {
        $a = new NormalEntity()->setAutofilledId('a')->setRequired('req');
        $b = new NormalEntity()->setAutofilledId('b')->setRequired('req');

        $result = $this->queryOutput((static function () use ($a, $b): \Generator {
            yield $a;
            yield $b;
        })(), 5);

        $page = $result->page();

        // No further page, so no cursor is built.
        static::assertSame([$a, $b], $page->items);
        static::assertNull($page->nextCursor);
    }

    public function testPageWithoutALimitReturnsEveryMatchAndNoCursor(): void
    {
        $a = new NormalEntity()->setAutofilledId('a')->setRequired('req');
        $b = new NormalEntity()->setAutofilledId('b')->setRequired('req');

        // No limit on the input: page() returns everything and never builds a cursor.
        $result = $this->queryOutput((static function () use ($a, $b): \Generator {
            yield $a;
            yield $b;
        })());

        $page = $result->page();

        static::assertSame([$a, $b], $page->items);
        static::assertNull($page->nextCursor);
    }

    public function testPageConsumesTheOutput(): void
    {
        $a = new NormalEntity()->setAutofilledId('a')->setRequired('req');
        $b = new NormalEntity()->setAutofilledId('b')->setRequired('req');

        $result = $this->queryOutput((static function () use ($a, $b): \Generator {
            yield $a;
            yield $b;
        })(), 1);

        $result->page();

        // page() consumes the single-use output; reading it again is a programming error. The next
        // page is a fresh search carrying the returned cursor, not a second call on this output.
        $this->expectException(\LogicException::class);
        $result->toArray();
    }

    /**
     * @param \Generator<int, NormalEntity> $source
     *
     * @return SearchOutput<NormalEntity>
     */
    private function queryOutput(\Generator $source, ?int $limit = null): SearchOutput
    {
        return new SearchOutput($source, $this->definition, new ScanInput(limit: $limit));
    }
}
