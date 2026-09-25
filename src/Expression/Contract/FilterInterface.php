<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Expression\Contract;

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
     * values it uses on the context.
     *
     * Returning `null` means "no contribution": the compiler and any logical group that contains the filter skip it.
     * 
     * Implementations MUST NOT register attribute names or values on the context when they return `null`, so empty children don't pollute the final request.
     *
     * @throws DALException if the filter names a field the entity does not have, or a value that does not serialize for it
     */
    public function compile(ExpressionCompileContext $context): ?string;
}
