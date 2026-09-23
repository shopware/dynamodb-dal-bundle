<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Client\Cursor;

use Shopware\DynamodbDalBundle\Client\Cursor\Cursor;
use Shopware\DynamodbDalBundle\Client\Cursor\CursorCollection;
use Shopware\DynamodbDalBundle\Client\Index;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CursorCollection::class)]
class CursorCollectionTest extends TestCase
{
    public function testEmptyByDefault(): void
    {
        $collection = new CursorCollection();

        static::assertTrue($collection->isEmpty());
        static::assertSame([], $collection->cursors);
    }

    public function testIsNotEmptyWithCursors(): void
    {
        static::assertFalse(new CursorCollection(['waiting' => $this->cursor()])->isEmpty());
    }

    public function testGetReturnsTheCursorOrNull(): void
    {
        $cursor = $this->cursor();
        $collection = new CursorCollection(['waiting' => $cursor]);

        static::assertSame($cursor, $collection->get('waiting'));
        static::assertNull($collection->get('approved'));
    }

    public function testWithAddsACursorOnANewImmutableInstance(): void
    {
        $original = new CursorCollection();
        $cursor = $this->cursor();

        $derived = $original->with('waiting', $cursor);

        static::assertNotSame($original, $derived);
        static::assertTrue($original->isEmpty());
        static::assertSame($cursor, $derived->get('waiting'));
    }

    public function testWithOverridesAnExistingCursor(): void
    {
        $first = $this->cursor('tenant-1');
        $second = $this->cursor('tenant-2');

        $collection = new CursorCollection(['waiting' => $first])->with('waiting', $second);

        static::assertSame($second, $collection->get('waiting'));
        static::assertCount(1, $collection->cursors);
    }

    public function testIteratesNameToCursor(): void
    {
        $waiting = $this->cursor('tenant-1');
        $approved = $this->cursor('tenant-2');

        $collection = new CursorCollection(['waiting' => $waiting, 'approved' => $approved]);

        static::assertSame(
            ['waiting' => $waiting, 'approved' => $approved],
            iterator_to_array($collection),
        );
    }

    public function testOffsetExistsAndOffsetGet(): void
    {
        $cursor = $this->cursor();
        $collection = new CursorCollection(['waiting' => $cursor]);

        // isset()/[] go through offsetExists()/offsetGet(); the missing/non-string cases call the methods
        // directly to keep static analysis from resolving the offsets at compile time.
        static::assertTrue(isset($collection['waiting']));
        static::assertSame($cursor, $collection['waiting']);

        $nonStringOffset = $this->mixed(0);
        static::assertFalse($collection->offsetExists('approved'));
        static::assertFalse($collection->offsetExists($nonStringOffset));
        static::assertNull($collection->offsetGet('approved'));
        static::assertNull($collection->offsetGet($nonStringOffset));
    }

    public function testOffsetSetThrows(): void
    {
        $collection = new CursorCollection();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('immutable');

        $collection['waiting'] = $this->cursor();
    }

    public function testOffsetUnsetThrows(): void
    {
        $collection = new CursorCollection(['waiting' => $this->cursor()]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('immutable');

        unset($collection['waiting']);
    }

    public function testJsonSerializeReturnsTheCursorMap(): void
    {
        $cursor = $this->cursor();
        $collection = new CursorCollection(['waiting' => $cursor]);

        static::assertSame(['waiting' => $cursor], $collection->jsonSerialize());
    }

    /**
     * Launders a value to `mixed` so the non-string ArrayAccess paths can be exercised without static
     * analysis rejecting the call as a type error.
     */
    private function mixed(mixed $value): mixed
    {
        return $value;
    }

    private function cursor(string $tenantId = 'tenant-1'): Cursor
    {
        return new Cursor('order', new Index($tenantId));
    }
}
