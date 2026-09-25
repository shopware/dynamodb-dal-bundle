<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Expression;

use AsyncAws\DynamoDb\ValueObject\AttributeValue;

/**
 * Output of {@see ExpressionCompiler::compileFilter()} and {@see ExpressionCompiler::compileUpdate()}: the compiled expression string plus
 * the `ExpressionAttributeNames` / `ExpressionAttributeValues` placeholder maps it
 * references.
 *
 * Typical single-expression usage (scan/query with one filter):
 *
 * ```
 * $result = $compiler->compileFilter($definition, $criteria);
 *
 * $client->scan([
 *     'TableName' => $definition->getTable(),
 *     ...$result->getExpression('filter'),
 *     ...$result->getExpressionAttributes(),
 * ]);
 * ```
 *
 * Combining a `KeyConditionExpression` and a `FilterExpression` on the same request —
 * both expressions need to share one placeholder map at the request level:
 *
 * ```
 * $key    = $compiler->compileFilter($definition, $keyFilter);
 * $filter = $compiler->compileFilter($definition, $filterFilter);
 *
 * $client->query([
 *     'TableName' => $definition->getTable(),
 *     ...$key->getExpression('key-condition'),
 *     ...$filter->getExpression('filter'),
 *     ...$key->getExpressionAttributes($filter),
 * ]);
 * ```
 *
 * @internal
 */
class ExpressionCompiledResult
{
    /**
     * @param array<string, string> $names `['#field' => 'field']`
     * @param array<string, AttributeValue> $values `[':field_0' => AttributeValue]`
     */
    public function __construct(
        public readonly ?string $expression = null,
        public readonly array $names = [],
        public readonly array $values = [],
    ) {
    }

    /**
     * Returns a spread-ready `[FilterExpression|KeyConditionExpression|ConditionExpression|UpdateExpression => $expression]` shape.
     * Empty when this result has no expression.
     *
     * @param 'filter'|'key-condition'|'condition'|'update' $key
     *
     * @return ($key is 'filter' ? array{FilterExpression?: string} : ($key is 'key-condition' ? array{KeyConditionExpression?: string} : ($key is 'condition' ? array{ConditionExpression?: string} : array{UpdateExpression?: string})))
     */
    public function getExpression(string $key): array
    {
        if ($this->expression === null) {
            return [];
        }

        return match ($key) {
            'filter' => ['FilterExpression' => $this->expression],
            'key-condition' => ['KeyConditionExpression' => $this->expression],
            'condition' => ['ConditionExpression' => $this->expression],
            'update' => ['UpdateExpression' => $this->expression],
        };
    }

    /**
     * Returns the spread-ready placeholder maps, optionally folding in the maps of further
     * results so a multi-expression request can share one `ExpressionAttributeNames` /
     * `ExpressionAttributeValues` pair.
     *
     * @return array{
     *   ExpressionAttributeNames?: array<string, string>,
     *   ExpressionAttributeValues?: array<string, AttributeValue>,
     * }
     */
    public function getExpressionAttributes(self ...$others): array
    {
        $merged = $this->merge(...$others);
        $params = [];

        if ($merged->names !== []) {
            $params['ExpressionAttributeNames'] = $merged->names;
        }

        if ($merged->values !== []) {
            $params['ExpressionAttributeValues'] = $merged->values;
        }

        return $params;
    }

    /**
     * Folds the placeholder maps of `$others` into this result, returning a new result that keeps
     * this one's expression but combines all `ExpressionAttributeNames` / `ExpressionAttributeValues`
     * — so several expressions built for the same request (e.g. a key condition and a filter) can
     * share one map. The compiler hands out unique placeholder names per call, so they never collide.
     */
    public function merge(self ...$others): self
    {
        return new self(
            $this->expression,
            array_merge($this->names, ...array_map(static fn (self $result): array => $result->names, $others)),
            array_merge($this->values, ...array_map(static fn (self $result): array => $result->values, $others)),
        );
    }
}
