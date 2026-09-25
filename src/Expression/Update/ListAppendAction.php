<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Expression\Update;

use Shopware\DynamodbDalBundle\Expression\Contract\NormalizableUpdateActionInterface;
use Shopware\DynamodbDalBundle\Expression\ExpressionCompileContext;
use Shopware\DynamodbDalBundle\Expression\Update;

/**
 * Adds elements to the end of a list, or to its start with `$prepend`, counting a missing list as empty:
 * `SET #path = list_append(if_not_exists(#path, :empty), :values)`.
 *
 * The entity's normalizer sees the elements as the value of the list, not the list they end up in. Where it removes
 * them, nothing is written, as for an empty list given: a missing list stays missing.
 */
final class ListAppendAction implements NormalizableUpdateActionInterface
{
    /**
     * @param mixed $values - the list {@see Update::append()} takes, or what the normalizer left in its place. The
     *                      list field's serializer refuses anything else on compile.
     */
    public function __construct(
        public readonly string $fieldName,
        public readonly mixed $values,
        public readonly bool $prepend = false,
    ) {
    }

    public function getClause(): UpdateClause
    {
        return UpdateClause::Set;
    }

    public function getPath(): string
    {
        return $this->fieldName;
    }

    public function getValue(): mixed
    {
        return $this->values;
    }

    public function withValue(mixed $value): self
    {
        return new self($this->fieldName, $value, $this->prepend);
    }

    public function compile(ExpressionCompileContext $context): ?string
    {
        // Appending nothing would still create a missing list, as an empty one
        if ($this->values === null || $this->values === []) {
            return null;
        }

        $attribute = $context->attribute($this->fieldName);
        $current = "if_not_exists({$attribute}, {$context->placeholder($this->fieldName, [])})";
        $values = $context->placeholder($this->fieldName, $this->values);

        return $this->prepend
            ? "{$attribute} = list_append({$values}, {$current})"
            : "{$attribute} = list_append({$current}, {$values})";
    }
}
