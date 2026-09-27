<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Expression\Filter;

use Shopware\DynamodbDalBundle\Definition\AttributeType;
use Shopware\DynamodbDalBundle\Expression\Contract\FilterInterface;
use Shopware\DynamodbDalBundle\Expression\FilterCompileContext;

/**
 * Matches a string that starts with the prefix. The prefix is sent as is, not serialized by the field, so
 * `Filter::beginsWith('id', '0190')` works on a `Uuid` field too.
 */
final readonly class BeginsWithFilter implements FilterInterface
{
    public function __construct(
        public string $fieldName,
        public string $prefix,
    ) {
    }

    public function compile(FilterCompileContext $context): string
    {
        return \sprintf(
            'begins_with(%s, %s)',
            $context->path($this->fieldName, AttributeType::String),
            $context->literal($this->prefix),
        );
    }
}
