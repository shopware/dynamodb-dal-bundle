<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Expression;

use Shopware\DynamodbDalBundle\Definition\IndexSchema;
use Shopware\DynamodbDalBundle\Definition\KeySchema;
use Shopware\DynamodbDalBundle\Exception\InvalidKeyConditionException;
use Shopware\DynamodbDalBundle\Expression\Filter;
use Shopware\DynamodbDalBundle\Expression\Filter\KeyFilter;
use Shopware\DynamodbDalBundle\Expression\FilterCompileContext;
use Shopware\DynamodbDalBundle\Test\CompiledExpression;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\NormalEntity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(KeyFilter::class)]
class KeyFilterTest extends TestCase
{
    public function testAHashKeyAloneCompilesToItsComparison(): void
    {
        $compiled = CompiledExpression::ofFilter(NormalEntity::createDefinition(), Filter::keyFilter(Filter::equals('name', 'n')));

        static::assertSame('name = "n"', $compiled->resolved());
    }

    public function testARangeKeyIsJoinedWithAnd(): void
    {
        $compiled = CompiledExpression::ofFilter(
            NormalEntity::createDefinition(),
            Filter::keyFilter(Filter::equals('name', 'n'), Filter::beginsWith('required', 'r')),
        );

        static::assertSame('name = "n" AND begins_with(required, "r")', $compiled->resolved());
    }

    /**
     * As a search's filter or a write's condition, the two criteria keep their meaning inside another filter.
     */
    public function testBothKeysAreWrappedInsideAnotherFilter(): void
    {
        $compiled = CompiledExpression::ofFilter(
            NormalEntity::createDefinition(),
            Filter::or(Filter::keyFilter(Filter::equals('name', 'n'), Filter::equals('required', 'r')), Filter::exists('autofilledId')),
        );

        static::assertSame('(name = "n" AND required = "r") OR attribute_exists(autofilledId)', $compiled->resolved());
    }

    /**
     * @return iterable<string, array{KeyFilter, string}>
     */
    public static function invalidKeyConditions(): iterable
    {
        $hashKey = Filter::equals('name', 'n');

        yield 'a field that is not the hash key' => [Filter::keyFilter(Filter::equals('autofilledId', 'a')), '"autofilledId" is not the hash key "name"'];
        yield 'a path into the hash key' => [Filter::keyFilter(Filter::equals('name.first', 'n')), '"name.first" is not the hash key "name"'];
        yield 'a field that is not the range key' => [Filter::keyFilter($hashKey, Filter::equals('autofilledId', 'a')), '"autofilledId" is not the range key "required"'];
        yield 'the size of the hash key' => [Filter::keyFilter(Filter::equals(Filter::size('name'), 1)), 'it compares the hash key with values only, not with the size of "name"'];
        yield 'the size of the range key' => [Filter::keyFilter($hashKey, Filter::lessThan(Filter::size('required'), 3)), 'it compares the range key with values only, not with the size of "required"'];
        yield 'the hash key with another field' => [Filter::keyFilter(Filter::equals('name', Filter::field('required'))), 'it compares the hash key with values only, not with the field "required"'];
        yield 'the range key with a size' => [Filter::keyFilter($hashKey, Filter::between('required', 'a', Filter::size('name'))), 'it compares the range key with values only, not with the size of "name"'];
    }

    #[DataProvider('invalidKeyConditions')]
    public function testAKeyConditionThatDynamoDbWouldRefuseIsRefused(KeyFilter $keyFilter, string $reason): void
    {
        $this->expectException(InvalidKeyConditionException::class);
        $this->expectExceptionMessage("index \"byName\" of item \"normal\": {$reason}");

        $keyFilter->compile(self::context(new IndexSchema('byName', 'name', 'required')));
    }

    public function testARangeKeyWhereTheKeyHasNoneIsRefused(): void
    {
        $this->expectException(InvalidKeyConditionException::class);
        $this->expectExceptionMessage('the table of item "normal": it checks a range key, but the key is "autofilledId" alone');

        Filter::keyFilter(Filter::equals('autofilledId', 'a'), Filter::beginsWith('required', 'r'))->compile(self::context(new KeySchema('autofilledId')));
    }

    /**
     * The type asks for `equals()` on the hash key; a caller that does not check its types still gets refused.
     */
    public function testAHashKeyComparedOtherwiseIsRefused(): void
    {
        $this->expectException(InvalidKeyConditionException::class);
        $this->expectExceptionMessage('the hash key can only be compared with equals(), not with ">"');

        /** @phpstan-ignore argument.type (deliberately not equals(), which the type asks for) */
        Filter::keyFilter(Filter::greaterThan('autofilledId', 'a'))->compile(self::context(new KeySchema('autofilledId')));
    }

    /**
     * Outside a key condition there is no key to name, but the shape is checked all the same.
     */
    public function testOutsideAKeyConditionOnlyTheFieldNamesGoUnchecked(): void
    {
        $this->expectException(InvalidKeyConditionException::class);
        $this->expectExceptionMessage('it compares the hash key with values only, not with the size of "name"');

        Filter::keyFilter(Filter::equals(Filter::size('name'), 1))->compile(self::context(null));
    }

    private static function context(IndexSchema|KeySchema|null $keyCondition): FilterCompileContext
    {
        return new FilterCompileContext(NormalEntity::createDefinition(), 'k', $keyCondition);
    }
}
