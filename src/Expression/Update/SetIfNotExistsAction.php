<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Expression\Update;

use Shopware\DynamodbDalBundle\Expression\Contract\NormalizableUpdateActionInterface;
use Shopware\DynamodbDalBundle\Expression\ExpressionCompileContext;

/**
 * Writes the value only where the path holds none yet: `SET #path = if_not_exists(#path, :value)`.
 * The value is stored as given, so it passes the entity's normalizer like a field that is set.
 *
 * A `null` value, given or left by the normalizer, writes nothing: the path stays absent where it was, and a stored
 * value is kept. It does not remove the path, which would drop a value this action promises to keep.
 */
final class SetIfNotExistsAction implements NormalizableUpdateActionInterface
{
    public function __construct(
        public readonly string $fieldName,
        public readonly mixed $value,
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
        return $this->value;
    }

    public function withValue(mixed $value): self
    {
        return new self($this->fieldName, $value);
    }

    public function compile(ExpressionCompileContext $context): ?string
    {
        if ($this->value === null) {
            return null;
        }

        $attribute = $context->attribute($this->fieldName);

        return "{$attribute} = if_not_exists({$attribute}, {$context->placeholder($this->fieldName, $this->value)})";
    }
}
