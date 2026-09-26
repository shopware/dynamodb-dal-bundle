<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Expression\Contract;

use Shopware\DynamodbDalBundle\Exception\ConditionEmptyException;
use Shopware\DynamodbDalBundle\Exception\DALException;
use Shopware\DynamodbDalBundle\Expression\ExpressionCompileContext;
use Shopware\DynamodbDalBundle\Expression\Filter;

/**
 * A condition on an item, built with {@see Filter} or of your own. It serves as a search's filter, a query's
 * key condition and a write's condition, and as a child of `Filter::and()`, `Filter::or()` and `Filter::not()`.
 */
interface FilterInterface
{
    /**
     * Compile this filter into a condition, such as `#status = :value`, registering the attribute names and
     * values it uses on the context. A filter that joins several clauses wraps them in parentheses itself.
     *
     * Returning `null` means "no contribution": a logical group that contains the filter skips it, a search drops
     * it, and a write's condition or a query's key condition refuses it with a {@see ConditionEmptyException}.
     * Implementations MUST NOT register attribute names or values on the context when they return `null`, so empty
     * children don't pollute the final request.
     * 
     * @phpstan-impure - the context is mutated
     *
     * @throws DALException if the filter names a field the entity does not have, or a value that does not serialize for it
     */
    public function compile(ExpressionCompileContext $context): ?string;
}
