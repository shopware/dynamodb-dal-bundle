<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Expression;

use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\IndexSchema;
use Shopware\DynamodbDalBundle\Definition\KeySchema;
use Shopware\DynamodbDalBundle\Exception\ConditionEmptyException;
use Shopware\DynamodbDalBundle\Exception\DALException;
use Shopware\DynamodbDalBundle\Exception\InvalidKeyConditionException;
use Shopware\DynamodbDalBundle\Expression\Contract\FilterInterface;
use Shopware\DynamodbDalBundle\Expression\Filter\KeyFilter;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Compiles a {@see FilterInterface} into an {@see ExpressionCompiledResult}:
 * a search's filter, the condition of a write, or a query's key condition.
 *
 * Value placeholders are unique per call, also against an {@see UpdateCompiler}'s, so results merge into one request.
 * Name placeholders are derived from the attribute, so every result agrees on them.
 *
 * @internal
 */
class FilterCompiler implements ResetInterface
{
    /**
     * Namespaces the value placeholders of a compile. f = Filter
     */
    private const string PREFIX = 'f_';

    /**
     * The number of the last compile, which its prefix carries.
     */
    private int $sequence = 0;

    /**
     * An empty result where the filter contributes nothing, such as an `and()` without children.
     *
     * @throws DALException if the filter names a field the entity does not have, or a value that does not serialize for it
     */
    public function filter(EntityDefinition $definition, FilterInterface $filter): ExpressionCompiledResult
    {
        $context = $this->createContext($definition);

        $compiled = $filter->compile($context);
        if ($compiled === null || trim($compiled) === '') {
            return new ExpressionCompiledResult();
        }

        return new ExpressionCompiledResult($compiled, $context->names, $context->values);
    }

    /**
     * Compiles the conditions of a write, which have to check something, into one that holds where all of them hold,
     * joined as `Filter::and()` joins its children.
     *
     * Without a condition, a write would go through unconditionally, so each of them has to compile to something, and
     * none can hide behind another, such as a caller's condition behind an update's check that its item exists.
     *
     * @throws ConditionEmptyException if a condition compiles to nothing
     * @throws DALException if a condition names a field the entity does not have, or a value that does not serialize for it
     */
    public function condition(EntityDefinition $definition, FilterInterface $condition, FilterInterface ...$conditions): ExpressionCompiledResult
    {
        $conditions = [$condition, ...array_values($conditions)];
        $context = $this->createContext($definition);

        $fragments = [];
        foreach ($conditions as $filter) {
            $context->isCompound = false;
            $fragment = $filter->compile($context);
            if ($fragment === null || trim($fragment) === '') {
                throw new ConditionEmptyException($definition);
            }

            // A lone condition needs no parentheses
            $fragments[] = \count($conditions) > 1 && $context->isCompound ? "({$fragment})" : $fragment;
        }

        return new ExpressionCompiledResult(implode(' AND ', $fragments), $context->names, $context->values);
    }

    /**
     * Compiles a query's key condition for the key of the table or index queried, which the {@see KeyFilter} checks
     * its criteria against as it compiles.
     *
     * @param ?IndexSchema $index - the index queried, `null` for the table
     *
     * @throws InvalidKeyConditionException if DynamoDB would refuse the key condition
     * @throws DALException if a key field's value does not serialize for it
     */
    public function keyCondition(EntityDefinition $definition, KeyFilter $keyFilter, ?IndexSchema $index = null): ExpressionCompiledResult
    {
        $context = $this->createContext($definition, $index ?? $definition->getKeySchema());

        return new ExpressionCompiledResult($keyFilter->compile($context), $context->names, $context->values);
    }

    public function reset(): void
    {
        $this->sequence = 0;
    }

    /**
     * @param IndexSchema|KeySchema|null $keyCondition - the key a key condition is compiled for, `null` for a filter or a condition
     */
    private function createContext(EntityDefinition $definition, IndexSchema|KeySchema|null $keyCondition = null): FilterCompileContext
    {
        return new FilterCompileContext($definition, self::PREFIX . dechex(++$this->sequence), $keyCondition);
    }
}
