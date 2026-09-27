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

        return new ExpressionCompiledResult(self::unwrap($compiled), $context->names, $context->values);
    }

    /**
     * Compiles the conditions of a write, which have to check something, into one that holds where all of them hold,
     * joined as `Filter::and()` joins its children. A condition that joins clauses comes in parentheses, so its `OR`
     * cannot bind to a neighbour, such as an update's check that its item exists.
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
            $fragment = $filter->compile($context);
            if ($fragment === null || trim($fragment) === '') {
                throw new ConditionEmptyException($definition);
            }

            $fragments[] = $fragment;
        }

        return new ExpressionCompiledResult(self::unwrap(implode(' AND ', $fragments)), $context->names, $context->values);
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

        return new ExpressionCompiledResult(self::unwrap($keyFilter->compile($context)), $context->names, $context->values);
    }

    public function reset(): void
    {
        $this->sequence = 0;
    }

    /**
     * Drops the parentheses that a filter joining clauses wraps itself in, where they enclose the whole expression.
     * Sent on its own, it has nothing to be kept apart from, and DynamoDB may refuse a key condition in parentheses.
     * Names and values are placeholders, so every parenthesis in an expression belongs to its structure.
     */
    private static function unwrap(string $expression): string
    {
        while (str_starts_with($expression, '(') && str_ends_with($expression, ')')) {
            // The first parenthesis has to close at the very end, unlike the one of `(a) OR (b)`
            $depth = 0;
            for ($i = 0, $last = \strlen($expression) - 1; $i < $last; ++$i) {
                $depth += match ($expression[$i]) {
                    '(' => 1,
                    ')' => -1,
                    default => 0,
                };

                if ($depth === 0) {
                    return $expression;
                }
            }

            $expression = substr($expression, 1, -1);
        }

        return $expression;
    }

    /**
     * @param IndexSchema|KeySchema|null $keyCondition - the key a key condition is compiled for, `null` for a filter or a condition
     */
    private function createContext(EntityDefinition $definition, IndexSchema|KeySchema|null $keyCondition = null): FilterCompileContext
    {
        return new FilterCompileContext($definition, self::PREFIX . dechex(++$this->sequence), $keyCondition);
    }
}
