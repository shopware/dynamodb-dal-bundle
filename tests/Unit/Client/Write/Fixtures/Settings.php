<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Client\Write\Fixtures;

/**
 * An object an entity holds, which a field serializer or the normalizer stores as a map.
 */
final readonly class Settings
{
    public function __construct(
        public string $theme,
        public string $locale,
    ) {
    }
}
