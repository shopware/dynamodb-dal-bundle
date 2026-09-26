<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Client;

use Shopware\DynamodbDalBundle\Client\Cursor;
use Shopware\DynamodbDalBundle\Client\CursorHistory;
use Shopware\DynamodbDalBundle\Exception\InvalidCursorException;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CursorHistory::class)]
class CursorHistoryTest extends TestCase
{
    public function testTheEmptyHistoryIsPageOne(): void
    {
        $history = new CursorHistory();

        static::assertSame(1, $history->page());
        static::assertNull($history->current());
        static::assertNull($history->previous());
        static::assertSame('', $history->toString());
    }

    public function testWalksForwardAndBack(): void
    {
        [$first, $second] = [self::token('a'), self::token('b')];

        $history = new CursorHistory()->advance($first)->advance($second);

        static::assertSame(3, $history->page());
        static::assertSame($second, $history->current());
        static::assertSame($first, $history->previous()?->current());
        static::assertSame(1, $history->previous()->previous()?->page());
    }

    public function testAdvanceEndsWhereThePageHasNoNextToken(): void
    {
        $history = new CursorHistory();

        static::assertNull($history->advance(null));
        static::assertSame([self::token('a')], $history->advance(self::token('a'))?->positions);
    }

    public function testRoundTripsThroughItsUrlSafeString(): void
    {
        $history = new CursorHistory([self::token('a'), CursorHistory::combine(['open' => self::token('b')])]);

        $string = $history->toString();

        static::assertMatchesRegularExpression('/^[A-Za-z0-9_.-]+$/', $string);
        static::assertEquals($history, CursorHistory::fromString($string));
    }

    public function testPatternMatchesExactlyWhatFromStringAccepts(): void
    {
        $history = new CursorHistory([self::token('a'), CursorHistory::combine(['open' => self::token('b')])]);

        static::assertMatchesRegularExpression(CursorHistory::PATTERN, $history->toString());

        foreach (self::invalidHistories() as [$invalid]) {
            static::assertDoesNotMatchRegularExpression(CursorHistory::PATTERN, $invalid);
        }
    }

    public function testAMissingParameterIsPageOne(): void
    {
        static::assertSame(1, CursorHistory::fromString(null)->page());
        static::assertSame([], CursorHistory::fromString('')->positions);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidHistories(): iterable
    {
        yield 'empty position' => ['a..b'];
        yield 'trailing separator' => ['a.'];
        yield 'not url-safe' => ['a b'];
        yield 'json' => ['{"pages":[]}'];
    }

    #[DataProvider('invalidHistories')]
    public function testRefusesAStringThatIsNoHistory(string $history): void
    {
        $this->expectException(InvalidCursorException::class);

        CursorHistory::fromString($history);
    }

    public function testCombinedPositionsSplitBackIntoTheirNamedTokens(): void
    {
        $tokens = ['open' => self::token('a'), 'done' => self::token('b')];

        static::assertSame($tokens, CursorHistory::split(CursorHistory::combine($tokens)));
        static::assertSame([], CursorHistory::split(null));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function uncombinedPositions(): iterable
    {
        $encode = static fn (string $json): string => rtrim(strtr(base64_encode($json), '+/', '-_'), '=');

        yield 'plain token' => [self::token('a')];
        yield 'not base64' => ['!!!'];
        yield 'scalar' => [$encode('"open"')];
        yield 'list of tokens' => [$encode('["a","b"]')];
        yield 'non-string token' => [$encode('{"open":1}')];
    }

    public function testCombineRefusesANameThatIsNotValidUtf8(): void
    {
        $this->expectException(InvalidCursorException::class);

        CursorHistory::combine(["\xff" => self::token('a')]);
    }

    #[DataProvider('uncombinedPositions')]
    public function testSplitRefusesAPositionThatWasNotCombined(string $position): void
    {
        $this->expectException(InvalidCursorException::class);

        CursorHistory::split($position);
    }

    private static function token(string $id): string
    {
        return new Cursor(['autofilledId' => new AttributeValue(['S' => $id])])->encode();
    }
}
