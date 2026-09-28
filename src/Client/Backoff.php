<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Client;

/**
 * The pause before a request is sent again that DynamoDB did not carry out: the part of a batch it left unprocessed,
 * or a transaction it cancelled for a conflict or for throttling. AWS asks to back off exponentially, with jitter,
 * rather than to send it again right away.
 *
 * A batch has no limit on its rounds: where DynamoDB processes none of a request, it throws a
 * `ProvisionedThroughputExceededException` instead. So every round leaves fewer requests, and a chunk takes as many
 * rounds as it has requests at most.
 *
 * @internal
 */
final class Backoff
{
    private const int BASE_DELAY_MICROSECONDS = 50_000;

    private const int MAX_DELAY_MICROSECONDS = 1_000_000;

    /**
     * @param positive-int $round - how many times the request was not carried out so far
     */
    public static function wait(int $round): void
    {
        usleep(self::delay($round));
    }

    /**
     * Doubles each round up to a second. A random share of up to half of it is taken off, so that clients throttled
     * together don't send again together.
     *
     * @param positive-int $round
     *
     * @return int<0, max>
     */
    public static function delay(int $round): int
    {
        $delay = min(self::BASE_DELAY_MICROSECONDS << min($round - 1, 5), self::MAX_DELAY_MICROSECONDS);

        return max(0, $delay - mt_rand(0, intdiv($delay, 2)));
    }
}
