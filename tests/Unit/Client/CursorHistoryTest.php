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
     * PHP keeps a numeric name as an int key, both in the array given to combine() and in the one split() returns.
     * The lookup by name works all the same.
     */
    public function testCombinedPositionsWithNumericNamesSplitBackIntoTheirNamedTokens(): void
    {
        $tokens = ['200' => self::token('a'), '404' => self::token('b')];

        $split = CursorHistory::split(CursorHistory::combine($tokens));

        static::assertSame($tokens, $split);
        static::assertSame(self::token('a'), $split['200']);
    }

    /**
     * `['0' => $token]` is a list to PHP, which would encode as a JSON list without its names.
     */
    public function testCombinedPositionsNamedLikeAListSplitBackIntoTheirNamedTokens(): void
    {
        $tokens = ['0' => self::token('a'), '1' => self::token('b')];

        static::assertSame($tokens, CursorHistory::split(CursorHistory::combine($tokens)));
    }

    /**
     * A position combined as a JSON list, as combine() encoded such names before, names its tokens by position.
     */
    public function testAPositionCombinedAsAListSplitsIntoTokensNamedByPosition(): void
    {
        $position = rtrim(strtr(base64_encode(json_encode([self::token('a'), self::token('b')], \JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        static::assertSame([0 => self::token('a'), 1 => self::token('b')], CursorHistory::split($position));
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
