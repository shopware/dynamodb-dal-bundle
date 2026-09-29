<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Expression;

use Shopware\DynamodbDalBundle\Definition\AttributeType;
use Shopware\DynamodbDalBundle\Definition\FieldDefinition;
use Shopware\DynamodbDalBundle\Definition\KeySchema;
use Shopware\DynamodbDalBundle\Exception\AttributeTypeMismatchException;
use Shopware\DynamodbDalBundle\Expression\Contract\FilterInterface;
use Shopware\DynamodbDalBundle\Expression\ExpressionCompileContext;
use Shopware\DynamodbDalBundle\Expression\Filter;
use Shopware\DynamodbDalBundle\Expression\FilterCompileContext;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Exception\NullOperandException;
use Shopware\DynamodbDalBundle\Exception\UnknownFieldException;
use Shopware\DynamodbDalBundle\Exception\WrongTypeException;
use Shopware\DynamodbDalBundle\Serializer\Field\UidFieldSerializer;
use Shopware\DynamodbDalBundle\Tests\Unit\Definition\Fixtures\MapDefinition;
use Shopware\DynamodbDalBundle\Tests\Unit\Expression\Fixtures\CounterDefinition;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\NormalEntity;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

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
            '(#name = :h_0_name AND (#required = :h_1_required OR #required = :h_2_required))',
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
            '(#name = :h_0_name AND #name > :h_1_name AND #name >= :h_2_name AND #name < :h_3_name AND #name <= :h_4_name)',
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

        // Both operands are a part of the stored string rather than a value of the field, so neither carries its path.
        static::assertSame(
            '(begins_with(#name, :h_0) AND contains(#name, :h_1))',
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

        static::assertSame('(attribute_exists(#name) AND attribute_exists(#required))', $expression);
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
            '(NOT #name = :h_0_name AND NOT #name > :h_1_name)',
            $expression,
        );
    }

    public function testNotOfCompoundIsNotReWrappedByParent(): void
    {
        // The Or comes in its own parentheses, so `NOT` negates all of it, and the And takes
        // `NOT (...)` as one operand without a second pair around it.
        $filter = Filter::and(
            Filter::equals('name', 'foo'),
            Filter::not(Filter::or(
                Filter::equals('required', 'a'),
                Filter::equals('required', 'b'),
            )),
        );

        [$expression] = $this->compile($filter);

        static::assertSame(
            '(#name = :h_0_name AND NOT (#required = :h_1_required OR #required = :h_2_required))',
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

        // Value placeholders are numbered across the whole expression, so the IN values go on from :h_2.
        static::assertSame(
            '(NOT #name = :h_0_name AND NOT contains(#name, :h_1) AND NOT attribute_exists(#required) AND NOT #name IN (:h_2_name, :h_3_name))',
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

        static::assertSame('(#name = :h_0_name AND #required = :h_1_required)', $expression);
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
        $a = new FilterCompileContext(NormalEntity::createDefinition(), 'a');
        $b = new FilterCompileContext(NormalEntity::createDefinition(), 'b');

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
            '(#settings.#color = :h_0_settings_2ecolor AND #settings.#color = :h_1_settings_2ecolor AND #settings.#shape = :h_2_settings_2eshape)',
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
        // beyond the root is rejected.
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
        // `settings.0` is a Map key path — the `0` becomes a `#` attribute placeholder, not a list-index `[0]`.
        // Callers opt into list-index syntax explicitly via `tags[0]`.
        [$expression, $context] = $this->compile(
            Filter::equals('settings.0', 'foo'),
            MapDefinition::create(),
        );

        static::assertSame('#settings.#0 = :h_0_settings_2e0', $expression);
        static::assertSame(['#settings' => 'settings', '#0' => '0'], $context->names);
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

    /**
     * Guard against accidentally re-introducing auto-detection of numeric segments — the two syntaxes are
     * intentionally NOT interchangeable, and DynamoDB finds nothing under the one that does not fit the field.
     */
    #[DataProvider('pathIntoTheOtherCollectionProvider')]
    public function testAPathThatDoesNotFitTheCollectionThrows(string $path): void
    {
        $this->expectException(UnknownFieldException::class);
        $this->expectExceptionMessage("Unknown field \"{$path}\"");

        $this->compile(Filter::equals($path, 'foo'), MapDefinition::create());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pathIntoTheOtherCollectionProvider(): iterable
    {
        yield 'a numeric name inside a list' => ['tags.0'];
        yield 'an index into a map' => ['settings[0]'];
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
        // `deep.0.email` is fully dotted — every non-root segment is a Map key,
        // including the numeric `0`. Three name placeholders, no brackets.
        [$expression, $context] = $this->compile(
            Filter::equals('deep.0.email', 'a@b'),
            MapDefinition::create(),
        );

        static::assertSame('#deep.#0.#email = :h_0_deep_2e0_2eemail', $expression);
        static::assertSame(
            ['#deep' => 'deep', '#0' => '0', '#email' => 'email'],
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
     * DynamoDB refuses `<`, `<=`, `>`, `>=` and `BETWEEN` on anything but a string, a number or a binary.
     */
    #[DataProvider('orderingOfATypeWithoutOrderProvider')]
    public function testOrderingATypeWithoutOrderThrows(FilterInterface $filter, string $message): void
    {
        $this->expectException(AttributeTypeMismatchException::class);
        $this->expectExceptionMessage($message);

        $this->compile($filter, CounterDefinition::create());
    }

    /**
     * @return iterable<string, array{FilterInterface, string}>
     */
    public static function orderingOfATypeWithoutOrderProvider(): iterable
    {
        yield 'a list' => [Filter::greaterThan('tags', ['a']), '"tags" in item "counter" is of type L, where one of S, N, B is expected'];
        yield 'a boolean' => [Filter::lessThanOrEquals('active', true), '"active" in item "counter" is of type BOOL, where one of S, N, B is expected'];
        yield 'a map' => [Filter::between('meta', [], []), '"meta" in item "counter" is of type M, where one of S, N, B is expected'];
        yield 'a set' => [Filter::greaterThanOrEquals('labels', ['a']), '"labels" in item "counter" is of type SS, where one of S, N, B is expected'];
    }

    public function testEqualityComparesAnyType(): void
    {
        [$expression] = $this->compile(
            Filter::and(Filter::equals('tags', ['a']), Filter::notEquals('active', true)),
            CounterDefinition::create(),
        );

        static::assertSame('(#tags = :h_0_tags AND NOT #active = :h_1_active)', $expression);
    }

    public function testBeginsWithSendsThePrefixAsGivenWhateverThePhpTypeOfTheField(): void
    {
        $definition = new EntityDefinition('token', 'token', NormalEntity::class, null, [
            'id' => new FieldDefinition('id', Uuid::class, false, false, null, new UidFieldSerializer()),
        ], new KeySchema('id'));

        [$expression, $context] = $this->compile(Filter::beginsWith('id', '0190'), $definition);

        static::assertSame('begins_with(#id, :h_0)', $expression);
        static::assertEquals([':h_0' => new AttributeValue(['S' => '0190'])], $context->values);
    }

    #[DataProvider('fieldNotStoredAsAStringProvider')]
    public function testBeginsWithOnAFieldNotStoredAsAStringThrows(string $field, string $type): void
    {
        $this->expectException(AttributeTypeMismatchException::class);
        $this->expectExceptionMessage("\"{$field}\" in item \"counter\" is of type {$type}, where S is expected");

        $this->compile(Filter::beginsWith($field, 'a'), CounterDefinition::create());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function fieldNotStoredAsAStringProvider(): iterable
    {
        yield 'a number' => ['count', 'N'];
        yield 'a list' => ['tags', 'L'];
        yield 'a set' => ['labels', 'SS'];
    }

    public function testContainsLooksForASubstringInsideAJsonEncodedField(): void
    {
        [$expression, $context] = $this->compile(
            Filter::and(Filter::contains('payload', 'needle'), Filter::contains('payload', ['a' => 1])),
            CounterDefinition::create(),
        );

        // A string is a part of the stored JSON as it is given; anything else is what the field would store for it.
        static::assertSame('(contains(#payload, :h_0) AND contains(#payload, :h_1_payload))', $expression);
        static::assertEquals([
            ':h_0' => new AttributeValue(['S' => 'needle']),
            ':h_1_payload' => new AttributeValue(['S' => '{"a":1}']),
        ], $context->values);
    }

    public function testContainsLooksForOneElementOfAListOrASet(): void
    {
        [$expression, $context] = $this->compile(
            Filter::and(Filter::contains('tags', 'blue'), Filter::contains('labels', 'red')),
            CounterDefinition::create(),
        );

        static::assertSame('(contains(#tags, :h_0_tags) AND contains(#labels, :h_1))', $expression);
        static::assertEquals([
            ':h_0_tags' => new AttributeValue(['S' => 'blue']),
            ':h_1' => new AttributeValue(['S' => 'red']),
        ], $context->values);
    }

    /**
     * A binary set holds binaries, so the member is sent as one. Serialized by the field, it would be a set, which
     * matches no row.
     */
    public function testContainsLooksForTheBytesOfOneMemberOfABinarySet(): void
    {
        [$expression, $context] = $this->compile(Filter::contains('blobs', "\x00\x01"), CounterDefinition::create());

        static::assertSame('contains(#blobs, :h_0)', $expression);
        static::assertEquals([':h_0' => new AttributeValue(['B' => "\x00\x01"])], $context->values);
    }

    public function testBinaryLiteralRegistersTheBytesAsABinary(): void
    {
        $context = new ExpressionCompileContext(CounterDefinition::create(), self::PREFIX);

        static::assertSame(':h_0', $context->binaryLiteral("\xff"));
        static::assertEquals([':h_0' => new AttributeValue(['B' => "\xff"])], $context->values);
    }

    #[DataProvider('fieldThatContainsNothingProvider')]
    public function testContainsOnAFieldThatContainsNothingThrows(string $field, string $type): void
    {
        $this->expectException(AttributeTypeMismatchException::class);
        $this->expectExceptionMessage("\"{$field}\" in item \"counter\" is of type {$type}, where one of S, L, SS, NS, BS is expected");

        $this->compile(Filter::contains($field, 'a'), CounterDefinition::create());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function fieldThatContainsNothingProvider(): iterable
    {
        // DynamoDB looks for neither a key nor a value of a map.
        yield 'a map' => ['meta', 'M'];
        yield 'a number' => ['count', 'N'];
        yield 'a boolean' => ['active', 'BOOL'];
    }

    public function testAFieldWhoseSerializerDeclaresNoTypePassesEveryCheck(): void
    {
        [$expression] = $this->compile(
            Filter::and(
                Filter::equals(Filter::size('untyped'), 1),
                Filter::beginsWith('untyped', 'a'),
                Filter::contains('untyped', 'b'),
                Filter::greaterThan('untyped', Filter::field('count')),
                Filter::exists('untyped[0]'),
                Filter::exists('untyped.key'),
            ),
            CounterDefinition::create(),
        );

        static::assertSame(
            '(size(#untyped) = :h_0 AND begins_with(#untyped, :h_1) AND contains(#untyped, :h_2_untyped) AND #untyped > #count'
                . ' AND attribute_exists(#untyped[0]) AND attribute_exists(#untyped.#key))',
            $expression,
        );
    }

    public function testIsEmptyAlsoMatchesAMissingFieldWhereIsNotEmptyAsksForASize(): void
    {
        [$empty] = $this->compile(Filter::and(Filter::equals('name', 'a'), Filter::isEmpty('tags')), CounterDefinition::create());
        [$notEmpty] = $this->compile(Filter::isNotEmpty('tags'), CounterDefinition::create());

        static::assertSame('(#name = :h_0_name AND (NOT attribute_exists(#tags) OR size(#tags) = :h_1))', $empty);
        static::assertSame('size(#tags) > :h_0', $notEmpty);
    }

    public function testIsEmptyOfAFieldWithoutASizeThrows(): void
    {
        $this->expectException(AttributeTypeMismatchException::class);
        $this->expectExceptionMessage('"count" in item "counter" is of type N');

        $this->compile(Filter::isEmpty('count'), CounterDefinition::create());
    }

    public function testContainsAnyAndContainsAllCompileOneContainsPerValue(): void
    {
        [$expression] = $this->compile(
            Filter::and(Filter::containsAny('tags', ['a', 'b']), Filter::containsAll('labels', ['c', 'd'])),
            CounterDefinition::create(),
        );

        static::assertSame(
            '((contains(#tags, :h_0_tags) OR contains(#tags, :h_1_tags)) AND (contains(#labels, :h_2) AND contains(#labels, :h_3)))',
            $expression,
        );
    }

    public function testANullChildDropsOut(): void
    {
        [$expression, $context] = $this->compile(Filter::and(null, Filter::equals('name', 'a'), Filter::or(null, null)));

        static::assertSame('#name = :h_0_name', $expression);
        static::assertSame(['#name' => 'name'], $context->names);
    }

    public function testAFilterOfYourOwnNamesTheTypesItsPathHasToBeStoredAs(): void
    {
        $custom = new class implements FilterInterface {
            public function compile(ExpressionCompileContext $context): string
            {
                return "attribute_type({$context->path('tags', AttributeType::List)}, {$context->literal('L')})";
            }
        };

        [$expression, $context] = $this->compile($custom, CounterDefinition::create());

        static::assertSame('attribute_type(#tags, :h_0)', $expression);
        static::assertEquals([':h_0' => new AttributeValue(['S' => 'L'])], $context->values);
        static::assertSame(AttributeType::List, $context->fieldDefinition('tags')->getAttributeType());
        static::assertNull($context->fieldDefinition('untyped')->getAttributeType());
    }

    public function testAFilterOfYourOwnThatParenthesizesItsClausesIsNotWrappedAgain(): void
    {
        $custom = new class implements FilterInterface {
            public function compile(ExpressionCompileContext $context): string
            {
                return "({$context->path('name')} = {$context->fieldValue('name', 'a')} OR attribute_exists({$context->path('required')}))";
            }
        };

        [$expression] = $this->compile(Filter::and(Filter::equals('required', 'r'), $custom));
        [$negated] = $this->compile(Filter::not($custom));

        static::assertSame('(#required = :h_0_required AND (#name = :h_1_name OR attribute_exists(#required)))', $expression);
        static::assertSame('NOT (#name = :h_0_name OR attribute_exists(#required))', $negated);
    }

    public function testFieldValueSerializesWithTheFieldAndElementValueWithItsElements(): void
    {
        $context = new ExpressionCompileContext(CounterDefinition::create(), self::PREFIX);

        static::assertSame(':h_0_tags', $context->fieldValue('tags', ['a']));
        static::assertSame(':h_1_tags', $context->elementValue('tags', 'b'));
        static::assertEquals([
            ':h_0_tags' => new AttributeValue(['L' => [new AttributeValue(['S' => 'a'])]]),
            ':h_1_tags' => new AttributeValue(['S' => 'b']),
        ], $context->values);
    }

    public function testElementValueOfAFieldThatIsNoListOrMapThrows(): void
    {
        $this->expectException(AttributeTypeMismatchException::class);
        $this->expectExceptionMessage('"name" in item "counter" is of type S, where one of L, M is expected');

        new ExpressionCompileContext(CounterDefinition::create(), self::PREFIX)->elementValue('name', 'a');
    }

    public function testLiteralRegistersAStringAsAStringAndANumberAsANumber(): void
    {
        $context = new ExpressionCompileContext(CounterDefinition::create(), self::PREFIX);

        static::assertSame([':h_0', ':h_1', ':h_2'], [$context->literal('5'), $context->literal(5), $context->literal(1.5)]);
        static::assertEquals([
            ':h_0' => new AttributeValue(['S' => '5']),
            ':h_1' => new AttributeValue(['N' => '5']),
            ':h_2' => new AttributeValue(['N' => '1.5']),
        ], $context->values);
    }

    /**
     * A float keeps the digits a float field stores it with, where a cast to string would round `0.1 + 0.2` to `0.3`
     * and `0.9999999999999999` to `1`, and so compare with another number than the one given.
     */
    public function testLiteralWritesAFloatWithEveryDigitAFloatFieldStores(): void
    {
        $context = new ExpressionCompileContext(CounterDefinition::create(), self::PREFIX);

        $context->literal(0.1 + 0.2);
        $context->literal(0.9999999999999999);

        static::assertEquals([
            ':h_0' => new AttributeValue(['N' => '0.30000000000000004']),
            ':h_1' => new AttributeValue(['N' => '0.9999999999999999']),
        ], $context->values);
    }

    /**
     * @return array{0: ?string, 1: FilterCompileContext}
     */
    private function compile(FilterInterface $filter, ?EntityDefinition $definition = null): array
    {
        $context = new FilterCompileContext(
            $definition ?? NormalEntity::createDefinition(),
            self::PREFIX,
        );

        return [$filter->compile($context), $context];
    }
}
