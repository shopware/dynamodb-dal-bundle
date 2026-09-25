<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures;

use Shopware\DynamodbDalBundle\Serializer\AbstractNormalizer;
use Shopware\DynamodbDalBundle\Serializer\NormalizerContext;

/**
 * A normalizer whose two sides genuinely differ: the row stores `name` behind a prefix the entity never
 * carries. A normalizer that only fills missing values in cannot tell the two shapes apart, so it cannot
 * catch a write-back that applies the wrong one.
 */
class PrefixingNormalizer extends AbstractNormalizer
{
    public const string PREFIX = 'stored:';

    public function normalize(NormalizerContext $context): void
    {
        $name = $context->get('name');

        if (\is_string($name) && !str_starts_with($name, self::PREFIX)) {
            $context->set('name', self::PREFIX . $name);
        }
    }

    public function denormalize(NormalizerContext $context): void
    {
        $name = $context->get('name');

        if (\is_string($name) && str_starts_with($name, self::PREFIX)) {
            $context->set('name', substr($name, \strlen(self::PREFIX)));
        }
    }
}
