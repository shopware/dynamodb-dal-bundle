<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Expression\Filter;

use Shopware\DynamodbDalBundle\Expression\Contract\FilterInterface;
use Shopware\DynamodbDalBundle\Expression\ExpressionCompileContext;

final readonly class ExistsFilter implements FilterInterface
{
    public function __construct(
        public string $fieldName,
    ) {
    }

    public function compile(ExpressionCompileContext $context): string
    {
        return "attribute_exists({$context->path($this->fieldName)})";
    }
}
