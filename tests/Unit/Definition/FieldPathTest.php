<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Definition;

use Shopware\DynamodbDalBundle\Definition\FieldPath;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\DynamodbDalBundle\Tests\Unit\Definition\Fixtures\MapDefinition;

#[CoversClass(FieldPath::class)]
class FieldPathTest extends TestCase
{
    /**
     * @param non-empty-list<string|int> $segments
     */
    #[DataProvider('parsableProvider')]
    public function testParseSplitsThePathIntoSegments(string $path, array $segments, bool $nested): void
    {
        $parsed = $this->parse($path);

        static::assertSame($path, $parsed->path);
        static::assertSame($segments, $parsed->segments);
        static::assertSame($nested, $parsed->isNested());
    }

    /**
     * @return iterable<string, array{string, non-empty-list<string|int>, bool}>
     */
    public static function parsableProvider(): iterable
    {
        yield 'attribute' => ['settings', ['settings'], false];
        yield 'map entry' => ['settings.color', ['settings', 'color'], true];
        yield 'list element' => ['tags[0]', ['tags', 0], true];
        yield 'list element then map entry' => ['users[0].email', ['users', 0, 'email'], true];
        yield 'nested list elements' => ['matrix[0][1]', ['matrix', 0, 1], true];
        yield 'deep map entries' => ['deep.theme.color', ['deep', 'theme', 'color'], true];
        // A numeric map key is an attribute, not an index — `[0]` is what opts into list syntax.
        yield 'numeric map key' => ['tags.0', ['tags', '0'], true];
    }

    #[DataProvider('unparsableProvider')]
    public function testParseRejectsAPathThatAddressesNothing(string $path): void
    {
        static::assertNull(FieldPath::tryParse(MapDefinition::create(), $path));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unparsableProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'separators only' => ['..'];
        yield 'opens on a list index' => ['[0].email'];
        yield 'unknown root attribute' => ['doesNotExist'];
        yield 'unknown root of a nested path' => ['doesNotExist.color'];
        // `settings` holds strings, so a third segment would descend into a scalar.
        yield 'deeper than the type nests' => ['settings.color.shade'];
    }

    /**
     * A malformed path must not be read as the segments it happens to contain. Most of these name a
     * real, different place on the fixture once the stray characters are dropped — `settings[color]`
     * reads as `settings.color`, `settings.` as `settings`, `tags.[0]` as `tags[0]` — so accepting one
     * would write there while the caller filed it under what they actually typed.
     */
    #[DataProvider('malformedProvider')]
    public function testParseRejectsAPathTheGrammarDoesNotCoverWhole(string $path): void
    {
        static::assertNull(FieldPath::tryParse(MapDefinition::create(), $path));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedProvider(): iterable
    {
        yield 'trailing separator' => ['settings.'];
        yield 'leading separator' => ['.settings'];
        yield 'doubled separator' => ['settings..color'];
        yield 'index brackets around a name' => ['settings[color]'];
        yield 'unclosed index' => ['tags[0'];
        yield 'unopened index' => ['tags0]'];
        yield 'trailing text after an index' => ['tags[0]x'];
        yield 'separator before an index' => ['tags.[0]'];
        yield 'empty index' => ['tags[]'];
        yield 'non-numeric index' => ['tags[a]'];
    }

    public function testParseAcceptsWhitespaceInAMapKey(): void
    {
        // Only `.`, `[` and `]` carry meaning; a key is caller data and may hold anything else. The
        // grammar must not tighten into rejecting keys the table legitimately stores.
        $path = $this->parse('settings.a b');

        static::assertSame(['settings', 'a b'], $path->segments);
        static::assertSame('#settings.#a_20b', $path->getExpression());
    }

    public function testResolvesTheDefinitionOfWhatTheEndOfThePathAddresses(): void
    {
        // `settings` is the collection; `settings.color` is the string inside it.
        static::assertSame('array', $this->parse('settings')->definition->getType());
        static::assertSame('string', $this->parse('settings.color')->definition->getType());
        static::assertSame('string', $this->parse('deep.theme.color')->definition->getType());
    }

    public function testExpressionSpendsOnePlaceholderPerNamedSegment(): void
    {
        $path = $this->parse('settings.color');

        static::assertSame('#settings.#color', $path->getExpression());
        static::assertSame(['#settings' => 'settings', '#color' => 'color'], $path->getExpressionAttributeNames());
    }

    public function testExpressionWritesAListIndexLiterallyAndSpendsNoPlaceholderOnIt(): void
    {
        $path = $this->parse('users[0].email');

        static::assertSame('#users[0].#email', $path->getExpression());
        // An index addresses a position, not a name, so it registers nothing — DynamoDB rejects an
        // ExpressionAttributeNames entry the expression never mentions.
        static::assertSame(['#users' => 'users', '#email' => 'email'], $path->getExpressionAttributeNames());
    }

    public function testExpressionSpellsASegmentAPlaceholderCannotCarry(): void
    {
        $path = $this->parse('settings.foo-bar');

        static::assertSame('#settings.#foo_2dbar', $path->getExpression());
        static::assertSame([
            '#settings' => 'settings',
            // The placeholder is spelled for DynamoDB; the name it stands for is the key as given.
            '#foo_2dbar' => 'foo-bar',
        ], $path->getExpressionAttributeNames());
    }

    public function testTwoPathsOverTheSameAttributeSpendTheSamePlaceholder(): void
    {
        // Merging two separately compiled expressions can only ever restate an entry, never contradict
        // one — which is what lets an update and its condition travel in a single request.
        $names = [
            ...$this->parse('settings.color')->getExpressionAttributeNames(),
            ...$this->parse('settings.shape')->getExpressionAttributeNames(),
        ];

        static::assertSame([
            '#settings' => 'settings',
            '#color' => 'color',
            '#shape' => 'shape',
        ], $names);
    }

    /**
     * @param non-empty-string $a
     * @param non-empty-string $b
     */
    #[DataProvider('confusableKeysProvider')]
    public function testTwoMapKeysNeverSpendOnePlaceholder(string $a, string $b): void
    {
        // Every pair collapses under a lossy `[^A-Za-z0-9_] -> _` reduction, which is why the reduction
        // has to be reversible: otherwise one name would have to be counted off against the other.
        $first = $this->parse("settings.{$a}");
        $second = $this->parse("settings.{$b}");

        static::assertNotSame($first->getExpression(), $second->getExpression());
        static::assertNotSame($first->getAttributeValueName('v'), $second->getAttributeValueName('v'));
    }

    /**
     * @return iterable<string, array{non-empty-string, non-empty-string}>
     */
    public static function confusableKeysProvider(): iterable
    {
        yield 'dash against underscore' => ['sub-1', 'sub_1'];
        yield 'space against underscore' => ['a b', 'a_b'];
        yield 'colon against slash' => ['a:b', 'a/b'];
    }

    public function testAttributeValueNameCarriesThePrefixAndTheWholePath(): void
    {
        static::assertSame(':v_settings', $this->parse('settings')->getAttributeValueName('v'));
        // The separator is spelled too, so `settings.color` cannot be confused with an attribute
        // literally named `settings_color`.
        static::assertSame(':v_settings_2ecolor', $this->parse('settings.color')->getAttributeValueName('v'));
        static::assertSame(':h_settings_2ecolor', $this->parse('settings.color')->getAttributeValueName('h'));
    }

    public function testAttributeValueNameSeparatesPathsThatShareAnAttribute(): void
    {
        static::assertNotSame(
            $this->parse('settings.color')->getAttributeValueName('v'),
            $this->parse('settings.shape')->getAttributeValueName('v'),
        );
    }

    private function parse(string $path): FieldPath
    {
        $parsed = FieldPath::tryParse(MapDefinition::create(), $path);
        static::assertNotNull($parsed, "Expected \"{$path}\" to address something in the fixture.");

        return $parsed;
    }
}
