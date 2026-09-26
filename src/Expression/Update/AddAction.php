<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Expression\Update;

use Shopware\DynamodbDalBundle\Expression\Contract\UpdateActionInterface;
use Shopware\DynamodbDalBundle\Expression\ExpressionCompileContext;

/**
 * DynamoDB's `ADD #path :value`: adds to a number, counting a missing one as 0, or adds elements to a
 * set, creating a missing one. The operand goes through the field's serializer, so an `int` field refuses
 * a fractional step instead of storing one it would read back truncated.
 */
final readonly class AddAction implements UpdateActionInterface
{
    public function __construct(
        public string $fieldName,
        public mixed $value,
    ) {
    }

    public function getClause(): UpdateClause
    {
        return UpdateClause::Add;
    }

    public function compile(ExpressionCompileContext $context): string
    {
        return "{$context->path($this->fieldName)} {$context->value($this->fieldName, $this->value)}";
    }
}
