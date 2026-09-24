<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Expression\Update;

use Shopware\DynamodbDalBundle\Expression\Contract\NormalizableUpdateActionInterface;
use Shopware\DynamodbDalBundle\Expression\ExpressionCompileContext;

/**
 * Adds elements to the end of a list, or to its start with `$prepend`, counting a missing list as empty:
 * `SET #path = list_append(if_not_exists(#path, :empty), :values)`.
 *
 * The entity's normalizer sees the elements as the value of the list, not the list they end up in. Where it removes
 * them, nothing is appended, as for an empty list given.
 */
class ListAppendAction implements NormalizableUpdateActionInterface
{
    /**
     * @var list<mixed>
     */
    public readonly array $values;

    /**
     * @param list<mixed> $values
     */
    public function __construct(
        public readonly string $fieldName,
        array $values,
        public readonly bool $prepend = false,
    ) {
        $this->values = array_values($values);
    }

    public function getClause(): UpdateClause
    {
        return UpdateClause::Set;
    }

    public function getPath(): string
    {
        return $this->fieldName;
    }

    /**
     * @return list<mixed>
     */
    public function getValue(): array
    {
        return $this->values;
    }

    /**
     * @param ?list<mixed> $value - `null` where the normalizer removed the elements
     */
    public function withValue(mixed $value): self
    {
        return new self($this->fieldName, $value ?? [], $this->prepend);
    }

    public function compile(ExpressionCompileContext $context): ?string
    {
        $attribute = $context->attribute($this->fieldName);
        $current = "if_not_exists({$attribute}, {$context->placeholder($this->fieldName, [])})";
        $values = $context->placeholder($this->fieldName, $this->values);

        return $this->prepend
            ? "{$attribute} = list_append({$values}, {$current})"
            : "{$attribute} = list_append({$current}, {$values})";
    }
}
