<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Expression\Update;

use Shopware\DynamodbDalBundle\Definition\AttributeType;
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
        $path = $context->path($this->fieldName, AttributeType::StringSet, AttributeType::NumberSet, AttributeType::BinarySet);

        return "{$path} {$context->fieldValue($this->fieldName, $this->value)}";
    }
}
