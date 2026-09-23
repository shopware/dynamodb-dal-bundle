<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\CompilerPass;

/**
 * A type no serializer shipped with the bundle claims, so an entity using it only compiles when the
 * application contributes one of its own.
 */
final readonly class Money
{
    public function __construct(
        public int $cents,
        public string $currency,
    ) {
    }
}
