<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures;

use Shopware\DynamodbDalBundle\Serializer\AbstractNormalizer;

/**
 * A normalizer whose two sides genuinely differ: the row stores `name` behind a prefix the entity never
 * carries. A normalizer that only fills missing values in cannot tell the two shapes apart, so it cannot
 * catch a write-back that applies the wrong one.
 *
 * @extends AbstractNormalizer<array<string, mixed>, array<string, mixed>>
 */
class PrefixingNormalizer extends AbstractNormalizer
{
    public const string PREFIX = 'stored:';

    public function normalize(array $fields, array $keys): array
    {
        $name = $fields['name'] ?? null;

        if (isset($keys['name']) && \is_string($name) && !str_starts_with($name, self::PREFIX)) {
            $fields['name'] = self::PREFIX . $name;
        }

        return $fields;
    }

    public function denormalize(array $fields, array $keys): array
    {
        $name = $fields['name'] ?? null;

        if (isset($keys['name']) && \is_string($name) && str_starts_with($name, self::PREFIX)) {
            $fields['name'] = substr($name, \strlen(self::PREFIX));
        }

        return $fields;
    }
}
