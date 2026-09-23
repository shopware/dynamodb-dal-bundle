<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Criteria;

use Shopware\DynamodbDalBundle\Criteria\ExpressionCompiler;
use Shopware\DynamodbDalBundle\Criteria\Filter;
use Shopware\DynamodbDalBundle\Criteria\Filter\AndFilter;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\NormalEntity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExpressionCompiler::class)]
class ExpressionCompilerTest extends TestCase
{
    private ExpressionCompiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new ExpressionCompiler();
    }

    public function testCompileAcceptsAFilterDirectly(): void
    {
        $result = $this->compiler->compile(NormalEntity::createDefinition(), Filter::equals('name', 'foo'));

        static::assertIsString($result->expression);
        static::assertCount(1, $result->names);
        static::assertCount(1, $result->values);
    }

    public function testFilterThatCompilesToNullReturnsEmptyResult(): void
    {
        // An empty AndFilter compiles to null; the compiler must not produce a partial result.
        $result = $this->compiler->compile(NormalEntity::createDefinition(), new AndFilter());

        static::assertNull($result->expression);
        static::assertSame([], $result->names);
        static::assertSame([], $result->values);
    }

    public function testEachCompileGetsAFreshSequencePrefixForItsValues(): void
    {
        $a = $this->compiler->compile(NormalEntity::createDefinition(), Filter::equals('name', 'a'));
        $b = $this->compiler->compile(NormalEntity::createDefinition(), Filter::equals('name', 'b'));

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
        $result = $this->compiler->compile(
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
        $result = $this->compiler->compile(NormalEntity::createDefinition(), Filter::sizeEquals('name', 0));

        static::assertMatchesRegularExpression('/^size\(#\w+\) = :\w+$/', (string) $result->expression);
        static::assertSame(['name'], array_values($result->names));
        static::assertCount(1, $result->values);
        static::assertSame('0', array_values($result->values)[0]->getN());
    }

    public function testResetRewindsTheSequence(): void
    {
        // After `reset()`, subsequent compiles start the sequence over. Symfony's container
        // wires this for long-running apps; tests can also use it for a clean slate.
        $first = $this->compiler->compile(NormalEntity::createDefinition(), Filter::equals('name', 'a'));
        $this->compiler->reset();
        $afterReset = $this->compiler->compile(NormalEntity::createDefinition(), Filter::equals('name', 'a'));

        static::assertSame($first->expression, $afterReset->expression);
        static::assertSame($first->names, $afterReset->names);
        static::assertEquals($first->values, $afterReset->values);
    }
}
