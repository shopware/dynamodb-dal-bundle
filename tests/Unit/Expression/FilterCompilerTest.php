<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Expression;

use Shopware\DynamodbDalBundle\Exception\ConditionEmptyException;
use Shopware\DynamodbDalBundle\Expression\Filter;
use Shopware\DynamodbDalBundle\Expression\Filter\AndFilter;
use Shopware\DynamodbDalBundle\Expression\FilterCompiler;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\NormalEntity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FilterCompiler::class)]
class FilterCompilerTest extends TestCase
{
    private FilterCompiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new FilterCompiler();
    }

    public function testCompileAcceptsAFilterDirectly(): void
    {
        $result = $this->compiler->filter(NormalEntity::createDefinition(), Filter::equals('name', 'foo'));

        static::assertIsString($result->expression);
        static::assertCount(1, $result->names);
        static::assertCount(1, $result->values);
    }

    public function testFilterThatCompilesToNullReturnsEmptyResult(): void
    {
        // An empty AndFilter compiles to null; the compiler must not produce a partial result.
        $result = $this->compiler->filter(NormalEntity::createDefinition(), new AndFilter());

        static::assertNull($result->expression);
        static::assertSame([], $result->names);
        static::assertSame([], $result->values);
    }

    public function testEachCompileGetsAFreshSequencePrefixForItsValues(): void
    {
        $a = $this->compiler->filter(NormalEntity::createDefinition(), Filter::equals('name', 'a'));
        $b = $this->compiler->filter(NormalEntity::createDefinition(), Filter::equals('name', 'b'));

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
        // A key condition compiles the same way, and DynamoDB only accepts one as
        // `hashKey = :v [AND rangeKey <op> :v]`: an outer `(...)` fails with a ValidationException.
        $result = $this->compiler->filter(
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

    public function testASizeWrapsTheAttributeAndEmitsANumericOperand(): void
    {
        // `size()` yields a number regardless of the attribute type, so the operand must be
        // registered as an `N` value rather than serialized through the field (a string here).
        $result = $this->compiler->filter(NormalEntity::createDefinition(), Filter::equals(Filter::size('name'), 0));

        static::assertMatchesRegularExpression('/^size\(#\w+\) = :\w+$/', (string) $result->expression);
        static::assertSame(['name'], array_values($result->names));
        static::assertCount(1, $result->values);
        static::assertSame('0', array_values($result->values)[0]->getN());
    }

    public function testResetRewindsTheSequence(): void
    {
        // After `reset()`, subsequent compiles start the sequence over. Symfony's container
        // wires this for long-running apps; tests can also use it for a clean slate.
        $first = $this->compiler->filter(NormalEntity::createDefinition(), Filter::equals('name', 'a'));
        $this->compiler->reset();
        $afterReset = $this->compiler->filter(NormalEntity::createDefinition(), Filter::equals('name', 'a'));

        static::assertSame($first->expression, $afterReset->expression);
        static::assertSame($first->names, $afterReset->names);
        static::assertEquals($first->values, $afterReset->values);
    }

    public function testAConditionThatCompilesToNothingIsRefused(): void
    {
        $this->expectException(ConditionEmptyException::class);

        $this->compiler->condition(NormalEntity::createDefinition(), Filter::and(Filter::equalsAny('name', [])));
    }

    /**
     * Where every criterion is left out as `null`, the write would otherwise go through unconditionally.
     */
    public function testAConditionOfCriteriaThatAreAllLeftOutIsRefused(): void
    {
        $this->expectException(ConditionEmptyException::class);

        $this->compiler->condition(NormalEntity::createDefinition(), Filter::and(null, Filter::or(null)));
    }

    public function testSeveralConditionsAreJoinedAsFilterAndJoinsItsChildren(): void
    {
        $result = $this->compiler->condition(
            NormalEntity::createDefinition(),
            Filter::exists('autofilledId'),
            Filter::or(Filter::equals('name', 'a'), Filter::equals('name', 'b')),
        );

        static::assertSame('attribute_exists(#autofilledId) AND (#name = :f_1_0_name OR #name = :f_1_1_name)', $result->expression);
        static::assertSame(['#autofilledId' => 'autofilledId', '#name' => 'name'], $result->names);
        static::assertSame([':f_1_0_name', ':f_1_1_name'], array_keys($result->values));
    }

    /**
     * A key condition may be refused in parentheses, so a lone condition is sent as it compiles.
     */
    public function testALoneConditionIsNeverWrapped(): void
    {
        $result = $this->compiler->condition(
            NormalEntity::createDefinition(),
            Filter::and(Filter::equals('autofilledId', 'a'), Filter::equals('name', 'b')),
        );

        static::assertSame('#autofilledId = :f_1_0_autofilledId AND #name = :f_1_1_name', $result->expression);
    }

    /**
     * Beside another condition, such as an update's check that its item exists, it would otherwise go unnoticed.
     */
    public function testAConditionThatCompilesToNothingIsRefusedBesideAnother(): void
    {
        $this->expectException(ConditionEmptyException::class);

        $this->compiler->condition(NormalEntity::createDefinition(), Filter::exists('autofilledId'), Filter::and());
    }
}
