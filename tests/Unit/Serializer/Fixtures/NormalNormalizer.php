<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures;

use Shopware\DynamodbDalBundle\Serializer\AbstractNormalizer;
use Shopware\DynamodbDalBundle\Serializer\NormalizerContext;

class NormalNormalizer extends AbstractNormalizer
{
    public function normalize(NormalizerContext $context): void
    {
        $context->setIfUnset('autofilledId', 'test-id');
    }

    public function denormalize(NormalizerContext $context): void
    {
        $context->setIfUnset('autofilledId', 'test-id');
    }
}
