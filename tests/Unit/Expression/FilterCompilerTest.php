<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Expression;

use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\IndexSchema;
use Shopware\DynamodbDalBundle\Exception\ConditionEmptyException;
use Shopware\DynamodbDalBundle\Exception\InvalidKeyConditionException;
use Shopware\DynamodbDalBundle\Expression\Filter;
use Shopware\DynamodbDalBundle\Expression\Filter\AndFilter;
use Shopware\DynamodbDalBundle\Expression\Filter\BeginsWithFilter;
use Shopware\DynamodbDalBundle\Expression\Filter\BetweenFilter;
use Shopware\DynamodbDalBundle\Expression\Filter\Comparator;
use Shopware\DynamodbDalBundle\Expression\Filter\ComparisonFilter;
use Shopware\DynamodbDalBundle\Expression\FilterCompiler;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\NormalEntity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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
        // A search filter is sent as it compiles, without parentheses around the whole of it.
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
     * A lone condition needs no parentheses, so it is sent as it compiles.
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

    public function testAKeyConditionComparesTheHashKeyWithEquals(): void
    {
        $result = $this->compiler->keyCondition(NormalEntity::createDefinition(), Filter::keyFilter(Filter::equals('autofilledId', 'a')));

        static::assertSame('#autofilledId = :f_1_0_autofilledId', $result->expression);
    }

    /**
     * @return iterable<string, array{ComparisonFilter<Comparator>|BetweenFilter|BeginsWithFilter, string}>
     */
    public static function rangeKeyConditions(): iterable
    {
        yield 'equals' => [Filter::equals('required', 'r'), '#required = :f_1_1_required'];
        yield 'lessThan' => [Filter::lessThan('required', 'r'), '#required < :f_1_1_required'];
        yield 'lessThanOrEquals' => [Filter::lessThanOrEquals('required', 'r'), '#required <= :f_1_1_required'];
        yield 'greaterThan' => [Filter::greaterThan('required', 'r'), '#required > :f_1_1_required'];
        yield 'greaterThanOrEquals' => [Filter::greaterThanOrEquals('required', 'r'), '#required >= :f_1_1_required'];
        yield 'between' => [Filter::between('required', 'a', 'z'), '#required BETWEEN :f_1_1_required AND :f_1_2_required'];
        yield 'beginsWith' => [Filter::beginsWith('required', 'r'), 'begins_with(#required, :f_1_1)'];
    }

    /**
     * DynamoDB may refuse a key condition in parentheses, so the two criteria go out as they are.
     *
     * @param ComparisonFilter<Comparator>|BetweenFilter|BeginsWithFilter $rangeKey
     */
    #[DataProvider('rangeKeyConditions')]
    public function testAKeyConditionTakesOneCriterionOnTheRangeKey(ComparisonFilter|BetweenFilter|BeginsWithFilter $rangeKey, string $expected): void
    {
        $result = $this->compiler->keyCondition(self::indexedDefinition(), Filter::keyFilter(Filter::equals('name', 'n'), $rangeKey), self::index());

        static::assertSame("#name = :f_1_0_name AND {$expected}", $result->expression);
    }

    public function testAKeyConditionIsCheckedAgainstTheKeyOfTheTable(): void
    {
        $this->expectException(InvalidKeyConditionException::class);
        $this->expectExceptionMessage('the table of item "normal": "name" is not the hash key "autofilledId"');

        $this->compiler->keyCondition(self::indexedDefinition(), Filter::keyFilter(Filter::equals('name', 'n')));
    }

    public function testAKeyConditionIsCheckedAgainstTheKeyOfTheIndexQueried(): void
    {
        $this->expectException(InvalidKeyConditionException::class);
        $this->expectExceptionMessage('index "byName" of item "normal": "autofilledId" is not the hash key "name"');

        $this->compiler->keyCondition(self::indexedDefinition(), Filter::keyFilter(Filter::equals('autofilledId', 'a')), self::index());
    }

    /**
     * A definition that declares {@see index()}.
     *
     * @return EntityDefinition<NormalEntity>
     */
    private static function indexedDefinition(): EntityDefinition
    {
        return NormalEntity::createDefinition(indexes: ['byName' => self::index()]);
    }

    private static function index(): IndexSchema
    {
        return new IndexSchema('byName', 'name', 'required');
    }
}
