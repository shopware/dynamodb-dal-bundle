<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Expression\Filter;

use Shopware\DynamodbDalBundle\Expression\Filter;

/**
 * DynamoDB's `size()` of a field.
 */
final readonly class SizeOperand
{
    public function __construct(
        public string $fieldName,
    ) {
    }
}
