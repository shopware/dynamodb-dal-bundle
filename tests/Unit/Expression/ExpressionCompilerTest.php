<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Expression;

use Shopware\DynamodbDalBundle\Exception\UpdateEmptyException;
use Shopware\DynamodbDalBundle\Expression\ExpressionCompiler;
use Shopware\DynamodbDalBundle\Expression\Filter;
use Shopware\DynamodbDalBundle\Expression\Filter\AndFilter;
use Shopware\DynamodbDalBundle\Expression\Update;
use Shopware\DynamodbDalBundle\Expression\Update\AddAction;
use Shopware\DynamodbDalBundle\Expression\Update\ListAppendAction;
use Shopware\DynamodbDalBundle\Expression\Update\SetIfNotExistsAction;
use Shopware\DynamodbDalBundle\Serializer\NormalizerContext;
use Shopware\DynamodbDalBundle\Serializer\NormalizerOperation;
use Shopware\DynamodbDalBundle\Serializer\Serializer;
use Shopware\DynamodbDalBundle\Tests\Unit\Expression\Fixtures\CounterDefinition;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\NormalEntity;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\RecordingNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
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
     * A single map cannot hold a path twice, but the request has to, or DynamoDB would not refuse the overlap
     * and one of the two would win without a word.
     */
    public function testAPathThatIsAFieldAndTheValueOfAnActionStaysInBoth(): void
    {
        [$normalized] = $this->compiler->compileUpdate(
            CounterDefinition::create(),
            Update::with(Update::set('name', 'a'), Update::setIfNotExists('name', 'b')),
        );

        static::assertSame(['name' => 'b'], $normalized->fields);
        static::assertEquals([new SetIfNotExistsAction('name', 'b')], $normalized->actions);
    }

    public function testASetIfNotExistsTheNormalizerLeavesWithoutAValueWritesNothing(): void
    {
        $normalizer = new RecordingNormalizer(static fn (NormalizerContext $context) => $context->remove('name'));

        [, $compiled] = $this->compiler->compileUpdate(
            CounterDefinition::create($normalizer),
            Update::with(Update::setIfNotExists('name', 'blank'), Update::set('count', 1)),
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
}
