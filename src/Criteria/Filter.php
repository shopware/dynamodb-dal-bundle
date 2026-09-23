<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Criteria;

use Shopware\DynamodbDalBundle\Criteria\Contract\FilterInterface;
use Shopware\DynamodbDalBundle\Criteria\Filter\AndFilter;
use Shopware\DynamodbDalBundle\Criteria\Filter\BeginsWithFilter;
use Shopware\DynamodbDalBundle\Criteria\Filter\BetweenFilter;
use Shopware\DynamodbDalBundle\Criteria\Filter\ContainsFilter;
use Shopware\DynamodbDalBundle\Criteria\Filter\EqualsAnyFilter;
use Shopware\DynamodbDalBundle\Criteria\Filter\EqualsFilter;
use Shopware\DynamodbDalBundle\Criteria\Filter\ExistsFilter;
use Shopware\DynamodbDalBundle\Criteria\Filter\GreaterThanFilter;
use Shopware\DynamodbDalBundle\Criteria\Filter\GreaterThanOrEqualsFilter;
use Shopware\DynamodbDalBundle\Criteria\Filter\LessThanFilter;
use Shopware\DynamodbDalBundle\Criteria\Filter\LessThanOrEqualsFilter;
use Shopware\DynamodbDalBundle\Criteria\Filter\NotFilter;
use Shopware\DynamodbDalBundle\Criteria\Filter\OrFilter;
use Shopware\DynamodbDalBundle\Criteria\Filter\SizeEqualsFilter;

/**
 * Static factory for building filter trees passed to {@see Criteria}:
 *
 * ```
 * $criteria = new Criteria(
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

    public static function equals(string $fieldName, mixed $value): EqualsFilter
    {
        return new EqualsFilter($fieldName, $value);
    }

    /**
     * @param list<mixed> $values
     */
    public static function equalsAny(string $fieldName, array $values): EqualsAnyFilter
    {
        return new EqualsAnyFilter($fieldName, $values);
    }

    public static function greaterThan(string $fieldName, mixed $value): GreaterThanFilter
    {
        return new GreaterThanFilter($fieldName, $value);
    }

    public static function greaterThanOrEquals(string $fieldName, mixed $value): GreaterThanOrEqualsFilter
    {
        return new GreaterThanOrEqualsFilter($fieldName, $value);
    }

    public static function lessThan(string $fieldName, mixed $value): LessThanFilter
    {
        return new LessThanFilter($fieldName, $value);
    }

    public static function lessThanOrEquals(string $fieldName, mixed $value): LessThanOrEqualsFilter
    {
        return new LessThanOrEqualsFilter($fieldName, $value);
    }

    public static function between(string $fieldName, mixed $fromValue, mixed $toValue): BetweenFilter
    {
        return new BetweenFilter($fieldName, $fromValue, $toValue);
    }

    public static function beginsWith(string $fieldName, mixed $value): BeginsWithFilter
    {
        return new BeginsWithFilter($fieldName, $value);
    }

    public static function contains(string $fieldName, mixed $value): ContainsFilter
    {
        return new ContainsFilter($fieldName, $value);
    }

    public static function exists(string $fieldName): ExistsFilter
    {
        return new ExistsFilter($fieldName);
    }

    public static function sizeEquals(string $fieldName, int $value): SizeEqualsFilter
    {
        return new SizeEqualsFilter($fieldName, $value);
    }

    public static function and(FilterInterface ...$filters): AndFilter
    {
        return new AndFilter(...$filters);
    }

    public static function or(FilterInterface ...$filters): OrFilter
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
    public static function notEquals(string $fieldName, mixed $value): NotFilter
    {
        return new NotFilter(new EqualsFilter($fieldName, $value));
    }

    /**
     * Shorthand for `Filter::not(Filter::equalsAny(...))`
     *
     * @param list<mixed> $values
     */
    public static function notEqualsAny(string $fieldName, array $values): NotFilter
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
