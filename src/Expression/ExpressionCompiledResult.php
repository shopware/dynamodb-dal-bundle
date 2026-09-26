<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Expression;

use AsyncAws\DynamoDb\ValueObject\AttributeValue;

/**
 * Output of {@see ExpressionCompiler}: the compiled expression string plus the `ExpressionAttributeNames` /
 * `ExpressionAttributeValues` placeholder maps it references. The expressions of one request, such as a key
 * condition and a filter, share one pair of maps:
 *
 * ```
 * $keyResult = $compiler->compileCondition($definition, $keyCondition);
 * $filterResult = $compiler->compileFilter($definition, $filter);
 *
 * $client->query([
 *     'TableName' => $definition->getTable(),
 *     ...$keyResult->getExpression('key-condition'),
 *     ...$filterResult->getExpression('filter'),
 *     ...$keyResult->getExpressionAttributes($filterResult),
 * ]);
 * ```
 *
 * @internal
 */
class ExpressionCompiledResult
{
    /**
     * @param array<string, string> $names `['#field' => 'field']`
     * @param array<string, AttributeValue> $values `[':ex_1_0_field' => AttributeValue]`
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
     * A new result with this one's expression, and the placeholder maps of this one and `$others` combined. Value
     * placeholders are unique per compile and a name placeholder means the same in every result, so no entry
     * contradicts another.
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
