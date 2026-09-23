<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Attribute;

/**
 * @codeCoverageIgnore
 */
#[\Attribute(flags: \Attribute::TARGET_PROPERTY)]
class Field
{
    /**
     * Optional PHP type for map/list values when @var does not specify it (e.g. valueType: 'string').
     * When using @var list<string> or @var array<string, int>, valueType is inferred and this can be omitted.
     */
    public function __construct(
        public readonly ?string $valueType = null,
    ) {
    }
}
