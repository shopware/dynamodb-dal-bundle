<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Expression;

use Shopware\DynamodbDalBundle\Exception\ConditionEmptyException;
use Shopware\DynamodbDalBundle\Exception\UpdateDuplicatePathException;
use Shopware\DynamodbDalBundle\Exception\UpdateEmptyException;
use Shopware\DynamodbDalBundle\Exception\WrongTypeException;
use Shopware\DynamodbDalBundle\Expression\ExpressionCompiler;
use Shopware\DynamodbDalBundle\Expression\Filter;
use Shopware\DynamodbDalBundle\Expression\Filter\AndFilter;
use Shopware\DynamodbDalBundle\Expression\Update;
use Shopware\DynamodbDalBundle\Expression\Update\AddAction;
use Shopware\DynamodbDalBundle\Expression\Update\ListAppendAction;
use Shopware\DynamodbDalBundle\Expression\Update\SetIfNotExistsAction;
use Shopware\DynamodbDalBundle\Expression\Update\UpdateExpression;
use Shopware\DynamodbDalBundle\Serializer\NormalizerContext;
use Shopware\DynamodbDalBundle\Serializer\NormalizerOperation;
use Shopware\DynamodbDalBundle\Serializer\Serializer;
use Shopware\DynamodbDalBundle\Tests\Unit\Expression\Fixtures\CounterDefinition;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\NormalEntity;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\RecordingNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExpressionCompiler::class)]
class ExpressionCompilerTest extends TestCase
{
    private ExpressionCompiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new ExpressionCompiler(new Serializer());
    }

    public function testCompileAcceptsAFilterDirectly(): void
    {
        $result = $this->compiler->compileFilter(NormalEntity::createDefinition(), Filter::equals('name', 'foo'));

        static::assertIsString($result->expression);
        static::assertCount(1, $result->names);
        static::assertCount(1, $result->values);
    }

    public function testFilterThatCompilesToNullReturnsEmptyResult(): void
    {
        // An empty AndFilter compiles to null; the compiler must not produce a partial result.
        $result = $this->compiler->compileFilter(NormalEntity::createDefinition(), new AndFilter());

        static::assertNull($result->expression);
        static::assertSame([], $result->names);
        static::assertSame([], $result->values);
    }

    public function testEachCompileGetsAFreshSequencePrefixForItsValues(): void
    {
        $a = $this->compiler->compileFilter(NormalEntity::createDefinition(), Filter::equals('name', 'a'));
        $b = $this->compiler->compileFilter(NormalEntity::createDefinition(), Filter::equals('name', 'b'));

        // Two compiles hold different values for the same attribute, so the compiler hands each a
        // fresh prefix from its counter and their value placeholders stay apart.
        static::assertNotSame($a->expression, $b->expression);
        static::assertSame([], array_intersect_key($a->values, $b->values));

        // Their names do not need keeping apart: a name placeholder is derived from the attribute it
        // stands for, so both compiles agree on what `#name` means and merging restates it.
        static::assertSame($a->names, $b->names);

        $attributes = $a->getExpressionAttributes($b);
        static::assertCount(1, $attributes['ExpressionAttributeNames'] ?? []);
        static::assertCount(2, $attributes['ExpressionAttributeValues'] ?? []);
    }

    public function testTopLevelAndDoesNotWrapInOuterParentheses(): void
    {
        // Regression: an outer `(...)` around a top-level AND breaks DynamoDB
        // KeyConditionExpression, which only accepts `hashKey = :v [AND rangeKey <op> :v]`
        // and rejects the parenthesised form with a ValidationException at runtime.
        // A repository that feeds the compile output straight into a KeyConditionExpression on an
        // index depends on this.
        $result = $this->compiler->compileFilter(
            NormalEntity::createDefinition(),
            Filter::and(
                Filter::equals('name', 'foo'),
                Filter::equals('required', 'bar'),
            ),
        );

        static::assertIsString($result->expression);
        static::assertStringStartsNotWith('(', $result->expression);
        static::assertStringEndsNotWith(')', $result->expression);
    }

    public function testSizeEqualsWrapsTheAttributeAndEmitsANumericOperand(): void
    {
        // `size()` yields a number regardless of the attribute type, so the operand must be
        // registered as an `N` value rather than serialized through the field (a string here).
        $result = $this->compiler->compileFilter(NormalEntity::createDefinition(), Filter::sizeEquals('name', 0));

        static::assertMatchesRegularExpression('/^size\(#\w+\) = :\w+$/', (string) $result->expression);
        static::assertSame(['name'], array_values($result->names));
        static::assertCount(1, $result->values);
        static::assertSame('0', array_values($result->values)[0]->getN());
    }

    public function testResetRewindsTheSequence(): void
    {
        // After `reset()`, subsequent compiles start the sequence over. Symfony's container
        // wires this for long-running apps; tests can also use it for a clean slate.
        $first = $this->compiler->compileFilter(NormalEntity::createDefinition(), Filter::equals('name', 'a'));
        $this->compiler->reset();
        $afterReset = $this->compiler->compileFilter(NormalEntity::createDefinition(), Filter::equals('name', 'a'));

        static::assertSame($first->expression, $afterReset->expression);
        static::assertSame($first->names, $afterReset->names);
        static::assertEquals($first->values, $afterReset->values);
    }

    /**
     * One call, so a normalizer that acts on several keys sees the value an action selects beside the fields, and
     * never the action. Each value goes back where it came from, and a field the normalizer adds becomes a field.
     */
    public function testAnUpdateIsNormalizedInOneCallWithTheValuesItsActionsSelect(): void
    {
        $normalizer = new RecordingNormalizer(static function (NormalizerContext $context): void {
            $context->set('name', 'normalized a');
            $context->set('ratio', 2.5);
            $context->set('tags', ['X']);
            $context->set('meta.added', 1);
        });
        $update = Update::with(Update::set('name', 'a'), Update::setIfNotExists('ratio', 1.5), Update::append('tags', ['x']), Update::increment('count'));

        [$normalized] = $this->compiler->compileUpdate(CounterDefinition::create($normalizer), $update);

        static::assertSame([['normalize', NormalizerOperation::Update, ['name' => 'a', 'ratio' => 1.5, 'tags' => ['x']]]], $normalizer->calls);
        static::assertSame(['name' => 'normalized a', 'meta.added' => 1], $normalized->fields);
        static::assertEquals(
            [new SetIfNotExistsAction('ratio', 2.5), new ListAppendAction('tags', ['X']), new AddAction('count', 1)],
            $normalized->actions,
        );
        static::assertEquals(
            Update::with(Update::set('name', 'a'), Update::setIfNotExists('ratio', 1.5), Update::append('tags', ['x']), Update::increment('count')),
            $update,
        );
    }

    /**
     * The normalizer's map holds a path once, so one of two values would win without a word. Where the winner is
     * `null`, the other would even write nothing, and DynamoDB would never see the overlap.
     */
    #[DataProvider('pathsGivenTwoValuesProvider')]
    public function testAPathGivenTwoValuesIsRefused(UpdateExpression $update): void
    {
        $this->expectException(UpdateDuplicatePathException::class);
        $this->expectExceptionMessage('Update of item "counter" writes path "name" more than once');

        $this->compiler->compileUpdate(CounterDefinition::create(), $update);
    }

    /**
     * @return iterable<string, array{UpdateExpression}>
     */
    public static function pathsGivenTwoValuesProvider(): iterable
    {
        yield 'a field and the value of an action' => [Update::with(Update::set('name', 'a'), Update::setIfNotExists('name', 'b'))];
        yield 'a field and an action without a value' => [Update::with(Update::set('name', 'a'), Update::setIfNotExists('name', null))];
        yield 'an action without a value and a field' => [Update::with(Update::setIfNotExists('name', null), Update::set('name', 'a'))];
        yield 'a removed field and the value of an action' => [Update::with(Update::remove('name'), Update::setIfNotExists('name', 'a'))];
        yield 'the values of two actions' => [Update::with(Update::setIfNotExists('name', 'a'), Update::setIfNotExists('name', null), Update::set('count', 1))];
    }

    public function testAnAppendWithoutElementsHasNothingToWrite(): void
    {
        $this->expectException(UpdateEmptyException::class);

        $this->compiler->compileUpdate(CounterDefinition::create(), Update::append('tags', []));
    }

    public function testAnAppendTheNormalizerLeavesWithoutAListIsRefusedForItsField(): void
    {
        $normalizer = new RecordingNormalizer(static fn (NormalizerContext $context) => $context->set('tags', 'x'));

        try {
            $this->compiler->compileUpdate(CounterDefinition::create($normalizer), Update::append('tags', ['x']));
            static::fail('The append should have been refused.');
        } catch (WrongTypeException $exception) {
            static::assertSame('tags', $exception->fieldDefinition->getName());
            static::assertSame('string', $exception->actualType);
        }
    }

    public function testAnActionTheNormalizerLeavesWithoutAValueWritesNothing(): void
    {
        $normalizer = new RecordingNormalizer(static function (NormalizerContext $context): void {
            $context->remove('name');
            $context->remove('tags');
        });

        [, $compiled] = $this->compiler->compileUpdate(
            CounterDefinition::create($normalizer),
            Update::with(Update::setIfNotExists('name', 'blank'), Update::append('tags', ['x']), Update::set('count', 1)),
        );

        static::assertSame('SET #count = :ex_1_0_count', $compiled->expression);
        static::assertSame(['#count' => 'count'], $compiled->names);
    }

    /**
     * Leaving a path out means the update does not touch it, so the action writing it goes too, while one that
     * selects no value is kept.
     */
    public function testAnActionWhosePathTheNormalizerLeavesOutIsDropped(): void
    {
        $normalizer = new RecordingNormalizer(static function (NormalizerContext $context): void {
            $context->omit('name');
            $context->omit('tags');
        });

        [$normalized, $compiled] = $this->compiler->compileUpdate(
            CounterDefinition::create($normalizer),
            Update::with(Update::setIfNotExists('name', 'a'), Update::append('tags', ['x']), Update::increment('count')),
        );

        static::assertSame([], $normalized->fields);
        static::assertEquals([new AddAction('count', 1)], $normalized->actions);
        static::assertSame('ADD #count :ex_1_0_count', $compiled->expression);
    }

    /**
     * A field set to `null` is removed, but one the normalizer leaves out is not written at all.
     */
    public function testAFieldTheNormalizerLeavesOutIsNotWritten(): void
    {
        $normalizer = new RecordingNormalizer(static fn (NormalizerContext $context) => $context->omit('name'));

        [$normalized] = $this->compiler->compileUpdate(CounterDefinition::create($normalizer), Update::setFields(['name' => 'a', 'count' => 1]));

        static::assertSame(['count' => 1], $normalized->fields);
    }

    /**
     * DynamoDB rejects an empty update expression, so the update fails before a request is built.
     */
    public function testAnUpdateWithNothingToWriteIsRefused(): void
    {
        $this->expectException(UpdateEmptyException::class);

        $this->compiler->compileUpdate(CounterDefinition::create(), Update::setFields([]));
    }

    public function testAConditionThatCompilesToNothingIsRefused(): void
    {
        $this->expectException(ConditionEmptyException::class);

        $this->compiler->compileCondition(NormalEntity::createDefinition(), Filter::and(Filter::equalsAny('name', [])));
    }

    public function testSeveralConditionsAreJoinedAsFilterAndJoinsItsChildren(): void
    {
        $result = $this->compiler->compileCondition(
            NormalEntity::createDefinition(),
            Filter::exists('autofilledId'),
            Filter::or(Filter::equals('name', 'a'), Filter::equals('name', 'b')),
        );

        static::assertSame('attribute_exists(#autofilledId) AND (#name = :ex_1_0_name OR #name = :ex_1_1_name)', $result->expression);
        static::assertSame(['#autofilledId' => 'autofilledId', '#name' => 'name'], $result->names);
        static::assertSame([':ex_1_0_name', ':ex_1_1_name'], array_keys($result->values));
    }

    /**
     * A key condition may be refused in parentheses, so a lone condition is sent as it compiles.
     */
    public function testALoneConditionIsNeverWrapped(): void
    {
        $result = $this->compiler->compileCondition(
            NormalEntity::createDefinition(),
            Filter::and(Filter::equals('autofilledId', 'a'), Filter::equals('name', 'b')),
        );

        static::assertSame('#autofilledId = :ex_1_0_autofilledId AND #name = :ex_1_1_name', $result->expression);
    }

    /**
     * Beside another condition, such as an update's check that its item exists, it would otherwise go unnoticed.
     */
    public function testAConditionThatCompilesToNothingIsRefusedBesideAnother(): void
    {
        $this->expectException(ConditionEmptyException::class);

        $this->compiler->compileCondition(NormalEntity::createDefinition(), Filter::exists('autofilledId'), Filter::and());
    }
}
