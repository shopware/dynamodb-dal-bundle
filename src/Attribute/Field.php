<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Attribute;

use Shopware\DynamodbDalBundle\Definition\AttributeType;

/**
 * @codeCoverageIgnore
 */
#[\Attribute(flags: \Attribute::TARGET_PROPERTY)]
class Field
{
    /**
     * @param ?string $valueType - Optional PHP type for map/list values when @var does not specify it (e.g. valueType: 'string').
     *                           When using @var list<string> or @var array<string, int>, valueType is inferred and this can be omitted.
     * @param ?AttributeType $storedAs - the DynamoDB type the field is stored as. Only the serializers that store this
     *                                 type are asked for the field. `null` asks every serializer.
     *                                 The values of a list or map are not affected
     */
    public function __construct(
        public readonly ?string $valueType = null,
        public readonly ?AttributeType $storedAs = null,
    ) {
    }
}
