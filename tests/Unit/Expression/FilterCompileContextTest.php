<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Expression;

use Shopware\DynamodbDalBundle\Definition\AttributeType;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Exception\AttributeTypeMismatchException;
use Shopware\DynamodbDalBundle\Exception\NullOperandException;
use Shopware\DynamodbDalBundle\Exception\WrongTypeException;
use Shopware\DynamodbDalBundle\Expression\Contract\FilterInterface;
use Shopware\DynamodbDalBundle\Expression\Filter;
use Shopware\DynamodbDalBundle\Expression\FilterCompileContext;
use Shopware\DynamodbDalBundle\Expression\Filter\FieldOperand;
use Shopware\DynamodbDalBundle\Expression\Filter\SizeOperand;
use Shopware\DynamodbDalBundle\Tests\Unit\Definition\Fixtures\MapDefinition;
use Shopware\DynamodbDalBundle\Tests\Unit\Expression\Fixtures\CounterDefinition;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\NormalEntity;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A field or its size as a side of a comparison, as the comparison filters compile it against a filter's context.
 */
#[CoversClass(FilterCompileContext::class)]
class FilterCompileContextTest extends TestCase
{
    private const string PREFIX = 'h';

    public function testTheLeftHandSideIsAPathOrASize(): void
    {
        $context = new FilterCompileContext(CounterDefinition::create(), self::PREFIX);

        static::assertSame('#count', $context->operand('count'));
        static::assertSame('#count', $context->operand(new FieldOperand('count')));
        static::assertSame('size(#tags)', $context->operand(new SizeOperand('tags')));
        static::assertSame([], $context->values);
    }

    public function testAValueIsSerializedForTheFieldOrRegisteredAsANumberForASize(): void
    {
        $context = new FilterCompileContext(CounterDefinition::create(), self::PREFIX);

        static::assertSame(':h_0_count', $context->comparand('count', 3));
        static::assertSame(':h_1', $context->comparand(new SizeOperand('tags'), 3));
        static::assertEquals(
            [':h_0_count' => new AttributeValue(['N' => '3']), ':h_1' => new AttributeValue(['N' => '3'])],
            $context->values,
        );
    }

    /**
     * A filter of your own that joins several clauses says so, and is wrapped where it is nested, but not as a whole
     * condition, which DynamoDB may refuse in parentheses.
     */
    public function testAFilterOfYourOwnThatJoinsClausesIsWrappedOnlyWhereItIsNested(): void
    {
        $custom = new class implements FilterInterface {
            public function compile(FilterCompileContext $context): string
            {
                $fragment = "{$context->path('name')} = {$context->fieldValue('name', 'a')} OR attribute_exists({$context->path('count')})";
                $context->isCompound = true;

                return $fragment;
            }
        };

        [$alone] = $this->compile($custom, CounterDefinition::create());
        [$nested] = $this->compile(Filter::and(Filter::exists('tags'), $custom), CounterDefinition::create());
        [$negated] = $this->compile(Filter::not($custom), CounterDefinition::create());

        static::assertSame('#name = :h_0_name OR attribute_exists(#count)', $alone);
        static::assertSame('attribute_exists(#tags) AND (#name = :h_0_name OR attribute_exists(#count))', $nested);
        static::assertSame('NOT (#name = :h_0_name OR attribute_exists(#count))', $negated);
    }

    /**
     * A filter of your own may compile one of the bundle's comparisons, which checks the types for it.
     */
    public function testAFilterOfYourOwnComparesByCompilingABuiltInOne(): void
    {
        $filter = new class('tags', 3) implements FilterInterface {
            public function __construct(
                private string $fieldName,
                private int $max,
            ) {
            }

            public function compile(FilterCompileContext $context): string
            {
                return Filter::lessThanOrEquals(Filter::size($this->fieldName), $this->max)->compile($context);
            }
        };

        [$expression] = $this->compile($filter, CounterDefinition::create());

        static::assertSame('size(#tags) <= :h_0', $expression);
    }

    public function testASizeComparesAsANumber(): void
    {
        [$expression, $context] = $this->compile(Filter::greaterThan(Filter::size('tags'), 2), MapDefinition::create());

        // `size()` is a number whatever it measures, so the operand is no value of the list.
        static::assertSame('size(#tags) > :h_0', $expression);
        static::assertSame(['#tags' => 'tags'], $context->names);
        static::assertEquals([':h_0' => new AttributeValue(['N' => '2'])], $context->values);
    }

    public function testEveryComparisonTakesASize(): void
    {
        [$expression] = $this->compile(Filter::and(
            Filter::equals(Filter::size('name'), 0),
            Filter::notEquals(Filter::size('name'), 1),
            Filter::lessThanOrEquals(Filter::size('name'), 2),
            Filter::between(Filter::size('name'), 3, 4),
            Filter::equalsAny(Filter::size('name'), [5, 6.5]),
        ));

        static::assertSame(
            'size(#name) = :h_0 AND NOT size(#name) = :h_1 AND size(#name) <= :h_2 AND size(#name) BETWEEN :h_3 AND :h_4 AND size(#name) IN (:h_5, :h_6)',
            $expression,
        );
    }

    /**
     * DynamoDB measures nothing there, so the comparison would never match.
     */
    #[DataProvider('fieldWithoutASizeProvider')]
    public function testTheSizeOfAFieldWithoutOneThrows(string $field, string $type): void
    {
        $this->expectException(AttributeTypeMismatchException::class);
        $this->expectExceptionMessage("\"{$field}\" in item \"counter\" is of type {$type}, where one of S, B, L, M, SS, NS, BS is expected");

        $this->compile(Filter::equals(Filter::size($field), 0), CounterDefinition::create());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function fieldWithoutASizeProvider(): iterable
    {
        yield 'a number' => ['count', 'N'];
        yield 'a boolean' => ['active', 'BOOL'];
    }

    public function testASizeComparedWithSomethingOtherThanANumberThrows(): void
    {
        $this->expectException(WrongTypeException::class);
        $this->expectExceptionMessage('Expected type "int|float" for field "tags"');

        $this->compile(Filter::equals(Filter::size('tags'), 'none'), MapDefinition::create());
    }

    public function testASizeComparedWithNullThrows(): void
    {
        $this->expectException(NullOperandException::class);
        $this->expectExceptionMessage('Operand for field "tags"');

        $this->compile(Filter::equals(Filter::size('tags'), null), MapDefinition::create());
    }

    public function testAFieldComparesWithAnotherField(): void
    {
        [$expression, $context] = $this->compile(Filter::lessThan('name', Filter::field('required')));

        static::assertSame('#name < #required', $expression);
        static::assertSame(['#name' => 'name', '#required' => 'required'], $context->names);
        static::assertSame([], $context->values);
    }

    public function testASizeComparesWithANumberFieldOrAnotherSize(): void
    {
        [$expression] = $this->compile(
            Filter::and(
                Filter::equals(Filter::size('tags'), Filter::field('count')),
                Filter::greaterThan('count', Filter::size('tags')),
                Filter::lessThan(Filter::size('tags'), Filter::size('meta')),
                Filter::equalsAny('count', [1, Filter::size('meta')]),
            ),
            CounterDefinition::create(),
        );

        static::assertSame(
            'size(#tags) = #count AND #count > size(#tags) AND size(#tags) < size(#meta) AND #count IN (:h_0_count, size(#meta))',
            $expression,
        );
    }

    /**
     * DynamoDB takes two types as unequal, so `<>` would match every item and every other comparison none.
     */
    #[DataProvider('operandsOfDifferentTypesProvider')]
    public function testOperandsOfDifferentTypesThrow(FilterInterface $filter, string $message): void
    {
        $this->expectException(AttributeTypeMismatchException::class);
        $this->expectExceptionMessage($message);

        $this->compile($filter, CounterDefinition::create());
    }

    /**
     * @return iterable<string, array{FilterInterface, string}>
     */
    public static function operandsOfDifferentTypesProvider(): iterable
    {
        yield 'a string and a number' => [Filter::notEquals('name', Filter::field('count')), '"count" in item "counter" is of type N, where S is expected'];
        yield 'a size and a string' => [Filter::equals(Filter::size('tags'), Filter::field('name')), '"name" in item "counter" is of type S, where N is expected'];
        yield 'a string and a size' => [Filter::equals('name', Filter::size('tags')), '"size(tags)" in item "counter" is of type N, where S is expected'];
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
