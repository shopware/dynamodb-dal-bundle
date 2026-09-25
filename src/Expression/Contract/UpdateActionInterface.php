<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Expression\Contract;

use Shopware\DynamodbDalBundle\Exception\DALException;
use Shopware\DynamodbDalBundle\Expression\ExpressionCompileContext;
use Shopware\DynamodbDalBundle\Expression\Update\UpdateClause;
use Shopware\DynamodbDalBundle\Expression\Update\UpdateExpression;

/**
 * One action of an {@see UpdateExpression} whose value DynamoDB computes from the stored item, such as
 * `#counter = #counter + :one` in the SET clause.
 *
 * Unlike a {@see FilterInterface}, an action is only a fragment of its clause, so no filter slot accepts one.
 */
interface UpdateActionInterface
{
    public function getClause(): UpdateClause;

    /**
     * Compile this action into its fragment of the clause, without the clause keyword, registering the
     * attribute names and values it uses on the context.
     *
     * Returning `null` means "no contribution", and then nothing may be registered on the context.
     *
     * @throws DALException if the action names a field the entity does not have, or a value that does not serialize for it
     */
    public function compile(ExpressionCompileContext $context): ?string;
}
