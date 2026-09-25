<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Expression;

use Shopware\DynamodbDalBundle\Expression\Contract\FilterInterface;
use Shopware\DynamodbDalBundle\Expression\ExpressionCompileContext;
use Shopware\DynamodbDalBundle\Expression\Filter;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Exception\NullOperandException;
use Shopware\DynamodbDalBundle\Exception\UnknownFieldException;
use Shopware\DynamodbDalBundle\Exception\WrongTypeException;
use Shopware\DynamodbDalBundle\Tests\Unit\Definition\Fixtures\MapDefinition;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\NormalEntity;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExpressionCompileContext::class)]
class ExpressionCompileContextTest extends TestCase
{
    private const string PREFIX = 'h';

    public function testEqualsCompilesToBinaryExpression(): void
    {
        [$expression, $context] = $this->compile(Filter::equals('name', 'foo'));

        static::assertSame('#name = :h_0_name', $expression);
        static::assertSame(['#name' => 'name'], $context->names);
        static::assertEquals([':h_0_name' => new AttributeValue(['S' => 'foo'])], $context->values);
    }

    public function testReadmeExampleCompiles(): void
    {
        $filter = Filter::and(
            Filter::equals('name', 'something'),
            Filter::or(
                Filter::equals('required', 'a'),
                Filter::equals('required', 'b'),
            ),
        );

        [$expression, $context] = $this->compile($filter);

        static::assertSame(
            '#name = :h_0_name AND (#required = :h_1_required OR #required = :h_2_required)',
            $expression,
        );
        static::assertSame(
            ['#name' => 'name', '#required' => 'required'],
            $context->names,
        );
    }

    public function testAllBinaryOperatorsCompile(): void
    {
        $filter = Filter::and(
            Filter::equals('name', 'a'),
            Filter::greaterThan('name', 'c'),
            Filter::greaterThanOrEquals('name', 'd'),
            Filter::lessThan('name', 'e'),
            Filter::lessThanOrEquals('name', 'f'),
        );

        [$expression, $context] = $this->compile($filter);

        static::assertSame(
            '#name = :h_0_name AND #name > :h_1_name AND #name >= :h_2_name AND #name < :h_3_name AND #name <= :h_4_name',
            $expression,
        );
        static::assertEquals([
            ':h_0_name' => new AttributeValue(['S' => 'a']),
            ':h_1_name' => new AttributeValue(['S' => 'c']),
            ':h_2_name' => new AttributeValue(['S' => 'd']),
            ':h_3_name' => new AttributeValue(['S' => 'e']),
            ':h_4_name' => new AttributeValue(['S' => 'f']),
        ], $context->values);
    }

    public function testBetweenUsesTwoPlaceholders(): void
    {
        [$expression, $context] = $this->compile(Filter::between('name', 'aaa', 'zzz'));

        static::assertSame('#name BETWEEN :h_0_name AND :h_1_name', $expression);
        static::assertSame(['#name' => 'name'], $context->names);
        static::assertEquals([
            ':h_0_name' => new AttributeValue(['S' => 'aaa']),
            ':h_1_name' => new AttributeValue(['S' => 'zzz']),
        ], $context->values);
    }

    public function testEqualsAnyCompilesToInOperator(): void
    {
        // EqualsAnyFilter calls placeholder() once per value (left of `IN`) before
        // calling attribute() on the right side, so values get the lower indices.
        [$expression, $context] = $this->compile(Filter::equalsAny('name', ['a', 'b', 'c']));

        static::assertSame('#name IN (:h_0_name, :h_1_name, :h_2_name)', $expression);
        static::assertEquals([
            ':h_0_name' => new AttributeValue(['S' => 'a']),
            ':h_1_name' => new AttributeValue(['S' => 'b']),
            ':h_2_name' => new AttributeValue(['S' => 'c']),
        ], $context->values);
    }

    public function testEmptyEqualsAnyIsSkipped(): void
    {
        $filter = Filter::and(
            Filter::equals('name', 'foo'),
            Filter::equalsAny('name', []),
        );

        [$expression] = $this->compile($filter);

        // The And group unwraps to its single non-skipped child.
        static::assertSame('#name = :h_0_name', $expression);
    }

    public function testStandaloneEmptyEqualsAnyReturnsNull(): void
    {
        [$expression, $context] = $this->compile(Filter::equalsAny('name', []));

        static::assertNull($expression);
        static::assertSame([], $context->names);
        static::assertSame([], $context->values);
    }

    public function testBeginsWithAndContainsUseFunctionSyntax(): void
    {
        $filter = Filter::and(
            Filter::beginsWith('name', 'pre'),
            Filter::contains('name', 'mid'),
        );

        [$expression] = $this->compile($filter);

        static::assertSame(
            'begins_with(#name, :h_0_name) AND contains(#name, :h_1_name)',
            $expression,
        );
    }

    public function testExistsHasNoValuePlaceholder(): void
    {
        $filter = Filter::and(
            Filter::exists('name'),
            Filter::exists('required'),
        );

        [$expression, $context] = $this->compile($filter);

        static::assertSame('attribute_exists(#name) AND attribute_exists(#required)', $expression);
        static::assertSame(['#name' => 'name', '#required' => 'required'], $context->names);
        static::assertSame([], $context->values);
    }

    public function testNotOnLeafOperandsDoesNotAddParentheses(): void
    {
        $filter = Filter::and(
            Filter::not(Filter::equals('name', 'foo')),
            Filter::not(Filter::greaterThan('name', 'z')),
        );

        [$expression] = $this->compile($filter);

        static::assertSame(
            'NOT #name = :h_0_name AND NOT #name > :h_1_name',
            $expression,
        );
    }

    public function testNotOfCompoundIsNotReWrappedByParent(): void
    {
        // `NOT (...)` already parenthesises its operand, so a sibling And/Or must not
        // add a second outer pair. NotFilter resets `$context->isCompound` to advertise
        // this — without that reset the And below would emit `(NOT (...))`.
        $filter = Filter::and(
            Filter::equals('name', 'foo'),
            Filter::not(Filter::or(
                Filter::equals('required', 'a'),
                Filter::equals('required', 'b'),
            )),
        );

        [$expression] = $this->compile($filter);

        static::assertSame(
            '#name = :h_0_name AND NOT (#required = :h_1_required OR #required = :h_2_required)',
            $expression,
        );
    }

    public function testNegationShortcutsCompile(): void
    {
        $filter = Filter::and(
            Filter::notEquals('name', 'foo'),
            Filter::notContains('name', 'bar'),
            Filter::notExists('required'),
            Filter::notEqualsAny('name', ['x', 'y']),
        );

        [$expression] = $this->compile($filter);

        // For the IN clause, EqualsAnyFilter registers value placeholders BEFORE its
        // attribute placeholder — that's why the values are :h_2/:h_3 but the attribute
        // is #h_3.
        static::assertSame(
            'NOT #name = :h_0_name AND NOT contains(#name, :h_1_name) AND NOT attribute_exists(#required) AND NOT #name IN (:h_2_name, :h_3_name)',
            $expression,
        );
    }

    public function testEmptyAndFilterIsSkipped(): void
    {
        $filter = Filter::and(
            Filter::equals('name', 'foo'),
            Filter::and(),
        );

        [$expression] = $this->compile($filter);

        static::assertSame('#name = :h_0_name', $expression);
    }

    public function testEmptyOrFilterIsSkipped(): void
    {
        $filter = Filter::and(
            Filter::equals('name', 'foo'),
            Filter::or(),
        );

        [$expression] = $this->compile($filter);

        static::assertSame('#name = :h_0_name', $expression);
    }

    public function testNotWrappingEmptyFilterIsSkipped(): void
    {
        $filter = Filter::and(
            Filter::equals('name', 'foo'),
            Filter::not(Filter::and()),
        );

        [$expression] = $this->compile($filter);

        static::assertSame('#name = :h_0_name', $expression);
    }

    public function testSingleChildAndOrUnwraps(): void
    {
        $filter = Filter::and(
            Filter::and(Filter::equals('name', 'foo')),
            Filter::or(Filter::equals('required', 'bar')),
        );

        [$expression] = $this->compile($filter);

        static::assertSame('#name = :h_0_name AND #required = :h_1_required', $expression);
    }

    public function testRepeatedFieldCollapsesItsNameButNotItsValues(): void
    {
        // A name placeholder stands for the attribute it names, so the second reference registers the
        // same entry as the first. A value placeholder cannot collapse the same way — the two
        // occurrences compare against different values.
        $filter = Filter::and(
            Filter::equals('name', 'a'),
            Filter::equals('name', 'b'),
        );

        [, $context] = $this->compile($filter);

        static::assertSame(['#name' => 'name'], $context->names);
        static::assertEquals([
            ':h_0_name' => new AttributeValue(['S' => 'a']),
            ':h_1_name' => new AttributeValue(['S' => 'b']),
        ], $context->values);
    }

    public function testTwoContextsShareNamesAndKeepValuesApart(): void
    {
        $a = new ExpressionCompileContext(NormalEntity::createDefinition(), 'a');
        $b = new ExpressionCompileContext(NormalEntity::createDefinition(), 'b');

        $exprA = Filter::equals('name', 'foo')->compile($a);
        $exprB = Filter::equals('name', 'foo')->compile($b);

        // Independently compiled expressions agree on what `#name` means, so merging them into one
        // request restates the entry rather than contradicting it.
        static::assertSame('#name = :a_0_name', $exprA);
        static::assertSame('#name = :b_0_name', $exprB);
        static::assertSame($a->names, $b->names);

        // Values carry no such guarantee, so each context numbers its own under its prefix.
        static::assertSame([], array_intersect_key($a->values, $b->values));
    }

    public function testUnknownFieldThrows(): void
    {
        $this->expectException(UnknownFieldException::class);
        $this->expectExceptionMessage('Unknown field "doesNotExist"');

        $this->compile(Filter::equals('doesNotExist', 'x'));
    }

    public function testNullValueThrows(): void
    {
        $this->expectException(NullOperandException::class);
        $this->expectExceptionMessage('Operand for field "name"');

        $this->compile(Filter::equals('name', null));
    }

    public function testWrongTypeBubblesUp(): void
    {
        $this->expectException(WrongTypeException::class);

        $this->compile(Filter::equals('name', 123));
    }

    public function testDottedPathCompilesEverySegmentAsAttribute(): void
    {
        [$expression, $context] = $this->compile(
            Filter::equals('settings.color', 'red'),
            MapDefinition::create(),
        );

        static::assertSame('#settings.#color = :h_0_settings_2ecolor', $expression);
        static::assertSame(['#settings' => 'settings', '#color' => 'color'], $context->names);
        static::assertEquals(
            [':h_0_settings_2ecolor' => new AttributeValue(['S' => 'red'])],
            $context->values,
        );
    }

    public function testRepeatedDottedPathsCollapseOntoOneNamePerSegment(): void
    {
        $filter = Filter::and(
            Filter::equals('settings.color', 'red'),
            Filter::equals('settings.color', 'blue'),
            Filter::equals('settings.shape', 'pill'),
        );

        [$expression, $context] = $this->compile($filter, MapDefinition::create());

        static::assertSame(
            '#settings.#color = :h_0_settings_2ecolor AND #settings.#color = :h_1_settings_2ecolor AND #settings.#shape = :h_2_settings_2eshape',
            $expression,
        );
        // Three references, four distinct segments between them, three entries.
        static::assertSame(
            ['#settings' => 'settings', '#color' => 'color', '#shape' => 'shape'],
            $context->names,
        );
    }

    public function testDeeplyNestedPathDescendsThroughValueFieldDefinitions(): void
    {
        [$expression, $context] = $this->compile(
            Filter::equals('deep.theme.color', 'red'),
            MapDefinition::create(),
        );

        static::assertSame('#deep.#theme.#color = :h_0_deep_2etheme_2ecolor', $expression);
        static::assertSame(
            ['#deep' => 'deep', '#theme' => 'theme', '#color' => 'color'],
            $context->names,
        );
    }

    public function testDottedPathOnUnknownRootThrows(): void
    {
        // The full path appears in the message — easier to spot a bad call site than just the root segment.
        $this->expectException(UnknownFieldException::class);
        $this->expectExceptionMessage('Unknown field "doesNotExist.color"');

        $this->compile(Filter::equals('doesNotExist.color', 'red'), MapDefinition::create());
    }

    public function testDottedPathOnNonMapRootThrows(): void
    {
        // `name` is a plain string field — it has no `valueFieldDefinition`, so any path
        // beyond the root is rejected. Subsequent segments aren't otherwise validated, but
        // the root must opt into being traversable.
        $this->expectException(UnknownFieldException::class);
        $this->expectExceptionMessage('name.foo');

        $this->compile(Filter::equals('name.foo', 'baz'));
    }

    public function testPathDeeperThanTypeAllowsThrows(): void
    {
        // `settings` is Map<string, string> — one level of descent is allowed.
        // A third segment would try to descend into a scalar, so the path is rejected.
        $this->expectException(UnknownFieldException::class);
        $this->expectExceptionMessage('settings.deeply.nested.whatever');

        $this->compile(
            Filter::equals('settings.deeply.nested.whatever', 'baz'),
            MapDefinition::create(),
        );
    }

    public function testDottedPathWrongTypeBubblesUp(): void
    {
        // settings.color is string-typed at the leaf; passing an int triggers wrongType.
        $this->expectException(WrongTypeException::class);

        $this->compile(Filter::equals('settings.color', 123), MapDefinition::create());
    }

    public function testDottedNumericSegmentIsTreatedAsMapKey(): void
    {
        // `tags.0` is a Map key path — the `0` becomes a `#` attribute placeholder, not a list-index `[0]`.
        // Callers opt into list-index syntax explicitly via `tags[0]`.
        [$expression, $context] = $this->compile(
            Filter::equals('tags.0', 'foo'),
            MapDefinition::create(),
        );

        static::assertSame('#tags.#0 = :h_0_tags_2e0', $expression);
        static::assertSame(['#tags' => 'tags', '#0' => '0'], $context->names);
    }

    public function testBracketSegmentIsTreatedAsListIndex(): void
    {
        // `tags[0]` opts into list-index syntax — `0` becomes `[0]`, no extra name placeholder.
        [$expression, $context] = $this->compile(
            Filter::equals('tags[0]', 'foo'),
            MapDefinition::create(),
        );

        static::assertSame('#tags[0] = :h_0_tags_5b0_5d', $expression);
        static::assertSame(['#tags' => 'tags'], $context->names);
    }

    public function testDottedAndBracketFormsProduceDifferentExpressions(): void
    {
        // Guard against accidentally re-introducing auto-detection of numeric segments
        // — the two syntaxes are intentionally NOT interchangeable.
        [$dotted] = $this->compile(Filter::equals('tags.0', 'foo'), MapDefinition::create());
        [$bracket] = $this->compile(Filter::equals('tags[0]', 'foo'), MapDefinition::create());

        static::assertNotSame($dotted, $bracket);
    }

    public function testMixedBracketAndDottedSyntax(): void
    {
        // `users[0].email` → list index then map-key attribute.
        [$expression, $context] = $this->compile(
            Filter::equals('users[0].email', 'a@b'),
            MapDefinition::create(),
        );

        static::assertSame('#users[0].#email = :h_0_users_5b0_5d_2eemail', $expression);
        static::assertSame(['#users' => 'users', '#email' => 'email'], $context->names);
    }

    public function testChainedBracketSyntax(): void
    {
        [$expression, $context] = $this->compile(
            Filter::equals('matrix[0][1]', 'cell'),
            MapDefinition::create(),
        );

        static::assertSame('#matrix[0][1] = :h_0_matrix_5b0_5d_5b1_5d', $expression);
        static::assertSame(['#matrix' => 'matrix'], $context->names);
    }

    public function testDeepDottedNumericPathRegistersEverySegmentAsAttribute(): void
    {
        // `users.0.email` is fully dotted — every non-root segment is a Map key,
        // including the numeric `0`. Three name placeholders, no brackets.
        [$expression, $context] = $this->compile(
            Filter::equals('users.0.email', 'a@b'),
            MapDefinition::create(),
        );

        static::assertSame('#users.#0.#email = :h_0_users_2e0_2eemail', $expression);
        static::assertSame(
            ['#users' => 'users', '#0' => '0', '#email' => 'email'],
            $context->names,
        );
    }

    public function testSegmentWithSpecialCharsIsKeptVerbatimInNamesMap(): void
    {
        // The placeholder is spelled for DynamoDB, which accepts only [A-Za-z0-9_]; the name it
        // stands for stays the segment as the caller wrote it.
        [$expression, $context] = $this->compile(
            Filter::equals('settings.foo-bar', 'baz'),
            MapDefinition::create(),
        );

        static::assertSame('#settings.#foo_2dbar = :h_0_settings_2efoo_2dbar', $expression);
        static::assertSame(
            ['#settings' => 'settings', '#foo_2dbar' => 'foo-bar'],
            $context->names,
        );
        static::assertEquals(
            [':h_0_settings_2efoo_2dbar' => new AttributeValue(['S' => 'baz'])],
            $context->values,
        );
    }

    /**
     * @return array{0: ?string, 1: ExpressionCompileContext}
     */
    private function compile(FilterInterface $filter, ?EntityDefinition $definition = null): array
    {
        $context = new ExpressionCompileContext(
            $definition ?? NormalEntity::createDefinition(),
            self::PREFIX,
        );

        return [$filter->compile($context), $context];
    }
}
