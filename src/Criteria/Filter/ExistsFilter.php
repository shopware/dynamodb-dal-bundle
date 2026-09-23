<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Criteria\Filter;

use Shopware\DynamodbDalBundle\Criteria\Contract\FilterInterface;
use Shopware\DynamodbDalBundle\Criteria\ExpressionCompileContext;

class ExistsFilter implements FilterInterface
{
    public function __construct(
        public readonly string $fieldName,
    ) {
    }

    public function compile(ExpressionCompileContext $context): ?string
    {
        return "attribute_exists({$context->attribute($this->fieldName)})";
    }
}
