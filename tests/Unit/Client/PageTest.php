<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Client;

use Shopware\DynamodbDalBundle\Client\Cursor;
use Shopware\DynamodbDalBundle\Client\Output\Page;
use Shopware\DynamodbDalBundle\Exception\InvalidCursorException;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\NormalEntity;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Page::class)]
class PageTest extends TestCase
{
    public function testCursorAfterResumesRightAfterTheGivenItem(): void
    {
        [$a, $b] = [self::entity('a'), self::entity('b')];
        $page = new Page([$a, $b], keys: [self::key('a'), self::key('b')]);

        static::assertEquals(new Cursor(self::key('a')), Cursor::decode($page->cursorAfter($a)));
        static::assertEquals(new Cursor(self::key('b')), Cursor::decode($page->cursorAfter($b)));
    }

    public function testCursorBeforeReadsBackwardFromTheGivenItem(): void
    {
        $a = self::entity('a');
        $page = new Page([$a], keys: [self::key('a')]);

        static::assertEquals(new Cursor(self::key('a'), backward: true), Cursor::decode($page->cursorBefore($a)));
    }

    public function testItemsAreMatchedByIdentityNotByEquality(): void
    {
        // An equal entity that is not the one on the page could carry any key; refusing it is the only safe answer.
        $page = new Page([self::entity('a')], keys: [self::key('a')]);

        $this->expectException(\InvalidArgumentException::class);
        $page->cursorAfter(self::entity('a'));
    }

    public function testATokenFromAPageBuiltWithoutKeysIsRefusedWhenUsed(): void
    {
        $a = self::entity('a');
        $page = new Page([$a], next: 'token');

        $this->expectException(InvalidCursorException::class);
        Cursor::decode($page->cursorAfter($a));
    }

    private static function entity(string $id): NormalEntity
    {
        return new NormalEntity()->setAutofilledId($id)->setRequired('req');
    }

    /**
     * @return array<string, AttributeValue>
     */
    private static function key(string $id): array
    {
        return ['autofilledId' => new AttributeValue(['S' => $id])];
    }
}
