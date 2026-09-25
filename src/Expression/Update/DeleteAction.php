<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Expression\Update;

use Shopware\DynamodbDalBundle\Expression\Contract\UpdateActionInterface;
use Shopware\DynamodbDalBundle\Expression\ExpressionCompileContext;
use Shopware\DynamodbDalBundle\Expression\Update;

/**
 * DynamoDB's `DELETE #path :value`: removes elements from a **set**.
 * To remove an attribute or a map entry, set it to `null` instead or use {@see Update::remove()}.
 */
final class DeleteAction implements UpdateActionInterface
{
    public function __construct(
        public readonly string $fieldName,
        public readonly mixed $value,
    ) {
    }

    public function getClause(): UpdateClause
    {
        return UpdateClause::Delete;
    }

    public function compile(ExpressionCompileContext $context): string
    {
        return "{$context->attribute($this->fieldName)} {$context->placeholder($this->fieldName, $this->value)}";
    }
}
