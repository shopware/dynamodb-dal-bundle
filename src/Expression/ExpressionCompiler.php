<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Expression;

use Shopware\DynamodbDalBundle\Exception\ConditionEmptyException;
use Shopware\DynamodbDalBundle\Exception\DALException;
use Shopware\DynamodbDalBundle\Exception\UpdateDuplicatePathException;
use Shopware\DynamodbDalBundle\Exception\UpdateEmptyException;
use Shopware\DynamodbDalBundle\Expression\Contract\FilterInterface;
use Shopware\DynamodbDalBundle\Expression\Contract\NormalizableUpdateActionInterface;
use Shopware\DynamodbDalBundle\Expression\Update\UpdateExpression;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Serializer\NormalizerOperation;
use Shopware\DynamodbDalBundle\Serializer\Serializer;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Compiles a whole expression, a {@see FilterInterface} or an {@see UpdateExpression}, into an
 * {@see ExpressionCompiledResult}. An update passes through the entity's normalizer first, as the fields of a put do.
 *
 * Each call results a result containing expression attributes that are unique
 * and can be merged with other results into one list without colliding keys.
 *
 * @internal
 */
class ExpressionCompiler implements ResetInterface
{
    /**
     * Namespaces the value placeholders of a compile. ex = Expression
     */
    private const string PREFIX = 'ex_';

    /**
     * Names in compiled results should be unique to allow merging
     * multiple results into one list without colliding keys
     */
    private int $sequence = 0;

    /**
     * @internal
     */
    public function __construct(
        private readonly Serializer $serializer,
    ) {
    }

    /**
     * An empty result where the filter contributes nothing, such as an `and()` without children.
     *
     * @throws DALException if the filter names a field the entity does not have, or a value that does not serialize for it
     */
    public function compileFilter(EntityDefinition $definition, FilterInterface $filter): ExpressionCompiledResult
    {
        return $this->compile($definition, $filter);
    }

    /**
     * Compiles conditions that have to check something, a write's condition or a query's key condition, into one that
     * holds where all of them hold, joined as `Filter::and()` joins its children. Without a condition, a write would go
     * through unconditionally and a query would be refused, so each of them has to compile to something, and none can
     * hide behind another, such as a caller's condition behind an update's check that its item exists.
     *
     * @throws ConditionEmptyException if a condition compiles to nothing
     * @throws DALException if a condition names a field the entity does not have, or a value that does not serialize for it
     */
    public function compileCondition(EntityDefinition $definition, FilterInterface $condition, FilterInterface ...$conditions): ExpressionCompiledResult
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

            // A lone condition is never wrapped, as a key condition in parentheses may be refused
            /** @phpstan-ignore-next-line booleanAnd.rightAlwaysFalse -- the flag can change in `->compile` calls */
            $fragments[] = \count($conditions) > 1 && $context->isCompound ? "({$fragment})" : $fragment;
        }

        return new ExpressionCompiledResult(implode(' AND ', $fragments), $context->names, $context->values);
    }

    /**
     * Normalizes the update, then compiles it.
     *
     * @throws UpdateEmptyException if the update has nothing to write
     * @throws UpdateDuplicatePathException
     * @throws DALException if the update names a field the entity does not have, or a value that does not serialize for it
     *
     * @return array{UpdateExpression, ExpressionCompiledResult} - the update as normalized, which is what an entity can take back, and its compiled form
     */
    public function compileUpdate(EntityDefinition $definition, UpdateExpression $update): array
    {
        $normalized = $this->normalizeUpdate($definition, $update);
        $compiled = $this->compile($definition, $normalized);

        if ($compiled->expression === null) {
            throw new UpdateEmptyException($definition);
        }

        return [$normalized, $compiled];
    }

    public function reset(): void
    {
        $this->sequence = 0;
    }

    /**
     * @throws DALException
     */
    private function compile(EntityDefinition $definition, FilterInterface|UpdateExpression $expression): ExpressionCompiledResult
    {
        $context = $this->createContext($definition);

        $compiled = $expression->compile($context);
        if ($compiled === null || trim($compiled) === '') {
            return new ExpressionCompiledResult();
        }

        return new ExpressionCompiledResult($compiled, $context->names, $context->values);
    }

    private function createContext(EntityDefinition $definition): ExpressionCompileContext
    {
        return new ExpressionCompileContext($definition, self::PREFIX . dechex(++$this->sequence));
    }

    /**
     * Passes the update's fields and the input of every {@see NormalizableUpdateActionInterface} through the entity's
     * normalizer in one call, keyed by path, so it sees every value the update stores as given and never an action.
     * Each action takes its input back, and one whose path the normalizer left out is dropped. A field the normalizer
     * adds becomes a field, and one it drops is not written.
     *
     * @throws UpdateDuplicatePathException
     */
    private function normalizeUpdate(EntityDefinition $definition, UpdateExpression $update): UpdateExpression
    {
        $inputs = [];
        foreach ($update->actions as $action) {
            if (!$action instanceof NormalizableUpdateActionInterface) {
                continue;
            }

            // The map holds one value per path, so a second one would replace the first without a word, and a
            // null among them would even hide the overlap from DynamoDB.
            $path = $action->getPath();
            if (\array_key_exists($path, $update->fields) || \array_key_exists($path, $inputs)) {
                throw new UpdateDuplicatePathException($definition, $path);
            }

            $inputs[$path] = $action->getValue();
        }

        $normalized = $this->serializer->normalize($definition, [...$update->fields, ...$inputs], NormalizerOperation::Update);

        $actions = [];
        foreach ($update->actions as $action) {
            if (!$action instanceof NormalizableUpdateActionInterface) {
                $actions[] = $action;
            } elseif (\array_key_exists($action->getPath(), $normalized)) {
                $actions[] = $action->withValue($normalized[$action->getPath()]);
            }
        }

        return new UpdateExpression(
            array_filter($normalized, static fn (string $path): bool => !\array_key_exists($path, $inputs), \ARRAY_FILTER_USE_KEY),
            $actions,
        );
    }
}
