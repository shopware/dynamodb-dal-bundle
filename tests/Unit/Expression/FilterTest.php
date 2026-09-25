<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Expression;

use Shopware\DynamodbDalBundle\Expression\Filter;
use Shopware\DynamodbDalBundle\Expression\Filter\AndFilter;
use Shopware\DynamodbDalBundle\Expression\Filter\BeginsWithFilter;
use Shopware\DynamodbDalBundle\Expression\Filter\BetweenFilter;
use Shopware\DynamodbDalBundle\Expression\Filter\ContainsFilter;
use Shopware\DynamodbDalBundle\Expression\Filter\EqualsAnyFilter;
use Shopware\DynamodbDalBundle\Expression\Filter\EqualsFilter;
use Shopware\DynamodbDalBundle\Expression\Filter\ExistsFilter;
use Shopware\DynamodbDalBundle\Expression\Filter\GreaterThanFilter;
use Shopware\DynamodbDalBundle\Expression\Filter\GreaterThanOrEqualsFilter;
use Shopware\DynamodbDalBundle\Expression\Filter\LessThanFilter;
use Shopware\DynamodbDalBundle\Expression\Filter\LessThanOrEqualsFilter;
use Shopware\DynamodbDalBundle\Expression\Filter\NotFilter;
use Shopware\DynamodbDalBundle\Expression\Filter\OrFilter;
use Shopware\DynamodbDalBundle\Expression\Filter\SizeEqualsFilter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Filter::class)]
class FilterTest extends TestCase
{
    public function testEqualsAndComparators(): void
    {
        static::assertEquals(new EqualsFilter('a', 1), Filter::equals('a', 1));
        static::assertEquals(new GreaterThanFilter('a', 1), Filter::greaterThan('a', 1));
        static::assertEquals(new GreaterThanOrEqualsFilter('a', 1), Filter::greaterThanOrEquals('a', 1));
        static::assertEquals(new LessThanFilter('a', 1), Filter::lessThan('a', 1));
        static::assertEquals(new LessThanOrEqualsFilter('a', 1), Filter::lessThanOrEquals('a', 1));
        static::assertEquals(new BetweenFilter('a', 1, 2), Filter::between('a', 1, 2));
    }

    public function testEqualsAnyForwardsValues(): void
    {
        static::assertEquals(new EqualsAnyFilter('a', [1, 2, 3]), Filter::equalsAny('a', [1, 2, 3]));
    }

    public function testStringFunctionsAndExists(): void
    {
        static::assertEquals(new BeginsWithFilter('a', 'pre'), Filter::beginsWith('a', 'pre'));
        static::assertEquals(new ContainsFilter('a', 'sub'), Filter::contains('a', 'sub'));
        static::assertEquals(new ExistsFilter('a'), Filter::exists('a'));
        static::assertEquals(new SizeEqualsFilter('a', 0), Filter::sizeEquals('a', 0));
    }

    public function testLogicalGroupsForwardChildren(): void
    {
        $a = new EqualsFilter('a', 1);
        $b = new EqualsFilter('b', 2);

        $and = Filter::and($a, $b);
        static::assertSame([$a, $b], $and->filters);

        $or = Filter::or($a, $b);
        static::assertSame([$a, $b], $or->filters);

        $not = Filter::not($a);
        static::assertSame($a, $not->filter);
    }

    public function testNestedTreeAcceptsTheReadmeExample(): void
    {
        $tree = Filter::and(
            Filter::equals('name', 'something'),
            Filter::or(
                Filter::equals('counter', -1),
                Filter::equals('counter', 2),
            ),
        );

        static::assertEquals(
            new AndFilter(
                new EqualsFilter('name', 'something'),
                new OrFilter(
                    new EqualsFilter('counter', -1),
                    new EqualsFilter('counter', 2),
                ),
            ),
            $tree,
        );
    }

    public function testNegationShortcutsWrapTheirPositiveCounterpart(): void
    {
        static::assertEquals(
            new NotFilter(new EqualsFilter('a', 1)),
            Filter::notEquals('a', 1),
        );
        static::assertEquals(
            new NotFilter(new EqualsAnyFilter('a', [1, 2])),
            Filter::notEqualsAny('a', [1, 2]),
        );
        static::assertEquals(
            new NotFilter(new ContainsFilter('a', 'sub')),
            Filter::notContains('a', 'sub'),
        );
        static::assertEquals(
            new NotFilter(new ExistsFilter('a')),
            Filter::notExists('a'),
        );
    }

    public function testFactoryClassIsNotInstantiable(): void
    {
        $reflection = new \ReflectionClass(Filter::class);

        static::assertTrue($reflection->isFinal());
        static::assertNotNull($reflection->getConstructor());
        static::assertTrue($reflection->getConstructor()->isPrivate());
    }

    public function testWithAddsChildrenToANewFilterAndLeavesTheOriginalAsItIs(): void
    {
        $a = Filter::equals('name', 'a');
        $b = Filter::equals('name', 'b');
        $and = Filter::and($a);
        $or = Filter::or($a);

        static::assertSame([$a, $b], $and->with($b)->filters);
        static::assertSame([$a], $and->filters);
        static::assertSame([$a, $b], $or->with($b)->filters);
        static::assertSame([$a], $or->filters);
    }
}
