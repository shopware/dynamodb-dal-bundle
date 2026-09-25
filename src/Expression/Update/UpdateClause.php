<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Expression\Update;

/**
 * The clauses of a DynamoDB update expression, in the order {@see UpdateExpression} writes them.
 * Each clause appears at most once and lists its actions separated by commas.
 */
enum UpdateClause: string
{
    case Set = 'SET';
    case Remove = 'REMOVE';
    case Add = 'ADD';
    case Delete = 'DELETE';
}
