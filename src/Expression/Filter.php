<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Expression;

use Shopware\DynamodbDalBundle\Exception\ConditionEmptyException;
use Shopware\DynamodbDalBundle\Expression\Contract\FilterInterface;
use Shopware\DynamodbDalBundle\Expression\Filter\AndFilter;
use Shopware\DynamodbDalBundle\Expression\Filter\BeginsWithFilter;
use Shopware\DynamodbDalBundle\Expression\Filter\BetweenFilter;
use Shopware\DynamodbDalBundle\Expression\Filter\Comparator;
use Shopware\DynamodbDalBundle\Expression\Filter\ComparisonFilter;
use Shopware\DynamodbDalBundle\Expression\Filter\ContainsFilter;
use Shopware\DynamodbDalBundle\Expression\Filter\EqualsAnyFilter;
use Shopware\DynamodbDalBundle\Expression\Filter\ExistsFilter;
use Shopware\DynamodbDalBundle\Expression\Filter\KeyFilter;
use Shopware\DynamodbDalBundle\Expression\Filter\NotFilter;
use Shopware\DynamodbDalBundle\Expression\Filter\OrFilter;
use Shopware\DynamodbDalBundle\Expression\Filter\FieldOperand;
use Shopware\DynamodbDalBundle\Expression\Filter\SizeOperand;

/**
 * Static factory for building expression trees passed to the client inputs:
 *
 * ```
 * $input = new ScanInput(
 *     OrderEntity::class,
 *     filter: Filter::and(
 *         Filter::equals('name', 'something'),
 *         Filter::or(
 *             Filter::equals('counter', -1),
 *             Filter::equals('counter', 2),
 *         ),
 *     ),
 * );
 * ```
 */
final class Filter
{
    private function __construct()
    {
    }

    /**
     * DynamoDB's `size()` of a field, to compare instead of the field: the length of a string or binary, or the number
     * of elements of a list, map or set.
     *
     * ```
     * Filter::equals(Filter::size('tags'), 0)
     * ```
     */
    public static function size(string $fieldName): SizeOperand
    {
        return new SizeOperand($fieldName);
    }

    /**
     * Another field, to compare with instead of a value. Both have to be stored as the same type.
     *
     * ```
     * Filter::greaterThan('updatedAt', Filter::field('createdAt'))
     * ```
     */
    public static function field(string $fieldName): FieldOperand
    {
        return new FieldOperand($fieldName);
    }

    /**
     * @param mixed $value - a value, a {@see self::field()} or a {@see self::size()}
     *
     * @return ComparisonFilter<Comparator::Equals>
     */
    public static function equals(string|SizeOperand $fieldName, mixed $value): ComparisonFilter
    {
        return new ComparisonFilter($fieldName, Comparator::Equals, $value);
    }

    /**
     * Without values it checks nothing and drops out: a search filter then matches every item, and a condition of
     * nothing else throws a {@see ConditionEmptyException}.
     *
     * @param list<mixed> $values - values, {@see self::field()} or {@see self::size()}
     */
    public static function equalsAny(string|SizeOperand $fieldName, array $values): EqualsAnyFilter
    {
        return new EqualsAnyFilter($fieldName, $values);
    }

    /**
     * @param mixed $value - a value, a {@see self::field()} or a {@see self::size()}
     *
     * @return ComparisonFilter<Comparator::GreaterThan>
     */
    public static function greaterThan(string|SizeOperand $fieldName, mixed $value): ComparisonFilter
    {
        return new ComparisonFilter($fieldName, Comparator::GreaterThan, $value);
    }

    /**
     * @param mixed $value - a value, a {@see self::field()} or a {@see self::size()}
     *
     * @return ComparisonFilter<Comparator::GreaterThanOrEquals>
     */
    public static function greaterThanOrEquals(string|SizeOperand $fieldName, mixed $value): ComparisonFilter
    {
        return new ComparisonFilter($fieldName, Comparator::GreaterThanOrEquals, $value);
    }

    /**
     * @param mixed $value - a value, a {@see self::field()} or a {@see self::size()}
     *
     * @return ComparisonFilter<Comparator::LessThan>
     */
    public static function lessThan(string|SizeOperand $fieldName, mixed $value): ComparisonFilter
    {
        return new ComparisonFilter($fieldName, Comparator::LessThan, $value);
    }

    /**
     * @param mixed $value - a value, a {@see self::field()} or a {@see self::size()}
     *
     * @return ComparisonFilter<Comparator::LessThanOrEquals>
     */
    public static function lessThanOrEquals(string|SizeOperand $fieldName, mixed $value): ComparisonFilter
    {
        return new ComparisonFilter($fieldName, Comparator::LessThanOrEquals, $value);
    }

    /**
     * @param mixed $fromValue - a value, a {@see self::field()} or a {@see self::size()}
     * @param mixed $toValue - a value, a {@see self::field()} or a {@see self::size()}
     */
    public static function between(string|SizeOperand $fieldName, mixed $fromValue, mixed $toValue): BetweenFilter
    {
        return new BetweenFilter($fieldName, $fromValue, $toValue);
    }

    /**
     * A query's key condition: the hash key compared with {@see self::equals()}, and at most one comparison,
     * {@see self::between()} or {@see self::beginsWith()} of the range key. A range key given as `null` drops out, for an optional criterion, and the query reads the whole partition:
     *
     * ```
     * Filter::keyFilter(
     *     Filter::equals('status', OrderStatus::Paid),
     *     $since !== null ? Filter::greaterThanOrEquals('createdAt', $since) : null,
     * )
     * ```
     *
     * @param ComparisonFilter<Comparator::Equals> $hashKey
     * @param ComparisonFilter<Comparator>|BetweenFilter|BeginsWithFilter|null $rangeKey
     */
    public static function keyFilter(ComparisonFilter $hashKey, ComparisonFilter|BetweenFilter|BeginsWithFilter|null $rangeKey = null): KeyFilter
    {
        return new KeyFilter($hashKey, $rangeKey);
    }

    /**
     * Matches a string field that starts with the prefix. See {@see BeginsWithFilter}.
     */
    public static function beginsWith(string $fieldName, string $prefix): BeginsWithFilter
    {
        return new BeginsWithFilter($fieldName, $prefix);
    }

    /**
     * Matches a string field that contains a substring, or a list or set field that contains an element. See
     * {@see ContainsFilter}.
     */
    public static function contains(string $fieldName, mixed $value): ContainsFilter
    {
        return new ContainsFilter($fieldName, $value);
    }

    /**
     * Matches a field that contains any of the values. Shorthand for `Filter::or()` of {@see self::contains()}, so
     * without values it checks nothing.
     *
     * @param list<mixed> $values
     */
    public static function containsAny(string $fieldName, array $values): OrFilter
    {
        return new OrFilter(...array_map(
            static fn (mixed $value): ContainsFilter => new ContainsFilter($fieldName, $value),
            array_values($values),
        ));
    }

    /**
     * Matches a field that contains all of the values. Shorthand for `Filter::and()` of {@see self::contains()}, so
     * without values it checks nothing.
     *
     * @param list<mixed> $values
     */
    public static function containsAll(string $fieldName, array $values): AndFilter
    {
        return new AndFilter(...array_map(
            static fn (mixed $value): ContainsFilter => new ContainsFilter($fieldName, $value),
            array_values($values),
        ));
    }

    public static function exists(string $fieldName): ExistsFilter
    {
        return new ExistsFilter($fieldName);
    }

    /**
     * Matches a string, list, map or set field that is empty or missing.
     * Shorthand for `Filter::or(Filter::notExists(...), Filter::equals(Filter::size(...), 0))`.
     */
    public static function isEmpty(string $fieldName): OrFilter
    {
        return new OrFilter(new NotFilter(new ExistsFilter($fieldName)), new ComparisonFilter(new SizeOperand($fieldName), Comparator::Equals, 0));
    }

    /**
     * The opposite of {@see self::isEmpty()}. Shorthand for `Filter::greaterThan(Filter::size(...), 0)`, as
     * `Filter::notEquals(Filter::size(...), 0)` would match a missing field too.
     *
     * @return ComparisonFilter<Comparator::GreaterThan>
     */
    public static function isNotEmpty(string $fieldName): ComparisonFilter
    {
        return new ComparisonFilter(new SizeOperand($fieldName), Comparator::GreaterThan, 0);
    }

    /**
     * Filters that check nothing drop out, and so does a `null`, for an optional criterion:
     *
     * ```
     * Filter::and(
     *     Filter::equals('customerId', $customerId),
     *     $createdBefore !== null ? Filter::lessThan('createdAt', $createdBefore) : null,
     * )
     * ```
     *
     * With none left, this checks nothing either.
     */
    public static function and(?FilterInterface ...$filters): AndFilter
    {
        return new AndFilter(...$filters);
    }

    /**
     * Filters that check nothing drop out, and so does a `null`, for an optional criterion.
     * With none left, this checks nothing either.
     */
    public static function or(?FilterInterface ...$filters): OrFilter
    {
        return new OrFilter(...$filters);
    }

    public static function not(FilterInterface $filter): NotFilter
    {
        return new NotFilter($filter);
    }

    /**
     * Shorthand for `Filter::not(Filter::equals(...))`
     */
    public static function notEquals(string|SizeOperand $fieldName, mixed $value): NotFilter
    {
        return new NotFilter(new ComparisonFilter($fieldName, Comparator::Equals, $value));
    }

    /**
     * Shorthand for `Filter::not(Filter::equalsAny(...))`
     *
     * @param list<mixed> $values
     */
    public static function notEqualsAny(string|SizeOperand $fieldName, array $values): NotFilter
    {
        return new NotFilter(new EqualsAnyFilter($fieldName, $values));
    }

    /**
     * Shorthand for `Filter::not(Filter::contains(...))`
     */
    public static function notContains(string $fieldName, mixed $value): NotFilter
    {
        return new NotFilter(new ContainsFilter($fieldName, $value));
    }

    /**
     * Shorthand for `Filter::not(Filter::exists(...))`
     */
    public static function notExists(string $fieldName): NotFilter
    {
        return new NotFilter(new ExistsFilter($fieldName));
    }
}
