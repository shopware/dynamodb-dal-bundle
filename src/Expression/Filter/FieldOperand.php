<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Expression\Filter;

use Shopware\DynamodbDalBundle\Expression\Filter;

/**
 * A field, compared in place of a value, as in `updatedAt > createdAt`.
 */
final readonly class FieldOperand
{
    public function __construct(
        public string $fieldName,
    ) {
    }
}
