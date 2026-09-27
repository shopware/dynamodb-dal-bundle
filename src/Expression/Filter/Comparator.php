<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Expression\Filter;

use Shopware\DynamodbDalBundle\Expression\Filter;

/**
 * The comparators of a {@see ComparisonFilter}: those DynamoDB takes in a key condition as well as in a filter.
 *
 * A key condition refuses `<>`, so {@see Filter::notEquals()} negates `=` instead, which matches the same items.
 */
enum Comparator: string
{
    case Equals = '=';
    case LessThan = '<';
    case LessThanOrEquals = '<=';
    case GreaterThan = '>';
    case GreaterThanOrEquals = '>=';
}
