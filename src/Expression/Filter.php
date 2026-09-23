<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Expression;

use Shopware\DynamodbDalBundle\Expression\Contract\ExpressionInterface;
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

/**
 * Static factory for building expression trees passed to the client inputs:
 *
 * ```
 * $input = new ScanInput(
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

    public static function and(ExpressionInterface ...$filters): AndFilter
    {
        return new AndFilter(...$filters);
    }

    public static function or(ExpressionInterface ...$filters): OrFilter
    {
        return new OrFilter(...$filters);
    }

    public static function not(ExpressionInterface $filter): NotFilter
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
