<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures;

use Shopware\DynamodbDalBundle\Serializer\AbstractNormalizer;

class NormalNormalizer extends AbstractNormalizer
{
    public function normalize(array $fields, array $keys): array
    {
        if (isset($keys['autofilledId'])) {
            $fields['autofilledId'] ??= 'test-id';
        }

        return $fields;
    }

    public function denormalize(array $fields, array $keys): array
    {
        if (isset($keys['autofilledId'])) {
            $fields['autofilledId'] ??= 'test-id';
        }

        return $fields;
    }
}
