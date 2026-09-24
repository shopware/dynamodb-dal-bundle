<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Expression;

use Shopware\DynamodbDalBundle\Exception\DALException;
use Shopware\DynamodbDalBundle\Expression\Contract\ExpressionInterface;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Compiles a {@see ExpressionInterface} into an {@see ExpressionCompiledResult}.
 *
 * Each `compile()` call results a result containing expression attributes that are unique
 * and can be merged with other results into one list without colliding keys.
 *
 * @internal
 */
class ExpressionCompiler implements ResetInterface
{
    /**
     * Necessary to avoid collisions with serialized expression names. ex = Expression
     */
    private const string PREFIX = 'ex_';

    /**
     * Names in compiled results should be unique to allow merging
     * multiple results into one list without colliding keys
     */
    private int $sequence = 0;

    /**
     * @throws DALException if the expression names a field the entity does not have, or a value that does not serialize for it
     */
    public function compile(EntityDefinition $definition, ExpressionInterface $filter): ExpressionCompiledResult
    {
        $context = new ExpressionCompileContext($definition, self::PREFIX . dechex(++$this->sequence));

        $expression = $filter->compile($context);
        if ($expression === null || trim($expression) === '') {
            return new ExpressionCompiledResult();
        }

        return new ExpressionCompiledResult($expression, $context->names, $context->values);
    }

    public function reset(): void
    {
        $this->sequence = 0;
    }
}
