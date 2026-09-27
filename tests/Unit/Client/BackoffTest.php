<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Client;

use Shopware\DynamodbDalBundle\Client\Backoff;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Backoff::class)]
class BackoffTest extends TestCase
{
    /**
     * The pause doubles each round up to a second, less a random share of up to half of it.
     */
    public function testThePauseDoublesEachRoundUpToASecond(): void
    {
        foreach ([1 => 50_000, 2 => 100_000, 3 => 200_000, 5 => 800_000, 6 => 1_000_000, 100 => 1_000_000] as $round => $full) {
            $delay = Backoff::delay($round);

            static::assertGreaterThanOrEqual(intdiv($full, 2), $delay, "round {$round}");
            static::assertLessThanOrEqual($full, $delay, "round {$round}");
        }
    }
}
