<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Expression\Update;

use Shopware\DynamodbDalBundle\Expression\Contract\UpdateActionInterface;
use Shopware\DynamodbDalBundle\Expression\ExpressionCompileContext;
use Shopware\DynamodbDalBundle\Expression\Update;

/**
 * DynamoDB's `DELETE #path :value`: removes elements from a **set**.
 * To remove an attribute or a map entry, set it to `null` instead or use {@see Update::remove()}.
 */
final readonly class DeleteAction implements UpdateActionInterface
{
    public function __construct(
        public string $fieldName,
        public mixed $value,
    ) {
    }

    public function getClause(): UpdateClause
    {
        return UpdateClause::Delete;
    }

    public function compile(ExpressionCompileContext $context): string
    {
        return "{$context->path($this->fieldName)} {$context->value($this->fieldName, $this->value)}";
    }
}
