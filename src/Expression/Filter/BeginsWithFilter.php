<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Expression\Filter;

use Shopware\DynamodbDalBundle\Expression\Contract\FilterInterface;
use Shopware\DynamodbDalBundle\Expression\ExpressionCompileContext;

final readonly class BeginsWithFilter implements FilterInterface
{
    public function __construct(
        public string $fieldName,
        public mixed $value,
    ) {
    }

    public function compile(ExpressionCompileContext $context): string
    {
        return \sprintf(
            'begins_with(%s, %s)',
            $context->path($this->fieldName),
            $context->value($this->fieldName, $this->value),
        );
    }
}
