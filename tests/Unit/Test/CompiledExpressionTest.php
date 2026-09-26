<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Test;

use Shopware\DynamodbDalBundle\Exception\UpdateEmptyException;
use Shopware\DynamodbDalBundle\Expression\Contract\FilterInterface;
use Shopware\DynamodbDalBundle\Expression\ExpressionCompileContext;
use Shopware\DynamodbDalBundle\Expression\Filter;
use Shopware\DynamodbDalBundle\Expression\Update;
use Shopware\DynamodbDalBundle\Expression\Update\SetIfNotExistsAction;
use Shopware\DynamodbDalBundle\Test\CompiledExpression;
use Shopware\DynamodbDalBundle\Tests\Unit\Expression\Fixtures\CounterDefinition;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\NormalEntity;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\PrefixingNormalizer;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompiledExpression::class)]
class CompiledExpressionTest extends TestCase
{
    public function testAFilterCompilesAsARequestSendsIt(): void
    {
        $compiled = CompiledExpression::ofFilter(CounterDefinition::create(), Filter::equals('name', 'a'));

        static::assertSame('#name = :ex_1_0_name', $compiled->expression);
        static::assertSame(['#name' => 'name'], $compiled->names);
        static::assertEquals([':ex_1_0_name' => new AttributeValue(['S' => 'a'])], $compiled->values);
    }

    public function testResolvedReadsTheExpressionWithoutItsPlaceholders(): void
    {
        $compiled = CompiledExpression::ofFilter(CounterDefinition::create(), Filter::and(
            Filter::or(Filter::equals('name', 'a'), Filter::greaterThan('count', 10)),
            Filter::exists('meta.seen'),
            Filter::contains('tags', 'x'),
        ));

        static::assertSame('(name = "a" OR count > 10) AND attribute_exists(meta.seen) AND contains(tags, "x")', $compiled->resolved());
    }

    public function testAFilterOfYourOwnCompilesAgainstTheDefinition(): void
    {
        $filter = new class implements FilterInterface {
            public function compile(ExpressionCompileContext $context): string
            {
                return \sprintf('size(%s) > %s', $context->path('tags'), $context->number(2));
            }
        };

        static::assertSame('size(tags) > 2', CompiledExpression::ofFilter(CounterDefinition::create(), $filter)->resolved());
    }

    public function testAFilterThatCompilesToNothingHasNoExpression(): void
    {
        $compiled = CompiledExpression::ofFilter(CounterDefinition::create(), Filter::and());

        static::assertNull($compiled->expression);
        static::assertNull($compiled->resolved());
    }

    public function testAnUpdateResolvesListsAndNumbers(): void
    {
        $compiled = CompiledExpression::ofUpdate(CounterDefinition::create(), Update::with(
            Update::append('tags', ['x', 'y']),
            Update::increment('count', 2),
        ));

        static::assertSame('SET tags = list_append(if_not_exists(tags, []), ["x", "y"]) ADD count 2', $compiled->resolved());
    }

    public function testAnUpdatePassesTheNormalizerAsAWriteDoes(): void
    {
        $compiled = CompiledExpression::ofUpdate(
            NormalEntity::createDefinition(new PrefixingNormalizer()),
            new SetIfNotExistsAction('name', 'first'),
        );

        static::assertSame('SET name = if_not_exists(name, "' . PrefixingNormalizer::PREFIX . 'first")', $compiled->resolved());
    }

    public function testAnUpdateThatWritesNothingIsRefused(): void
    {
        $this->expectException(UpdateEmptyException::class);

        CompiledExpression::ofUpdate(CounterDefinition::create(), Update::append('tags', []));
    }
}
