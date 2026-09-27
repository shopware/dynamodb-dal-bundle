<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Client\Write;

use Shopware\DynamodbDalBundle\Client\Backoff;
use AsyncAws\DynamoDb\Exception\TransactionCanceledException;
use AsyncAws\DynamoDb\ValueObject\CancellationReason;

/**
 * Sends a transaction again after a growing pause where DynamoDB cancelled it for a reason that passes: a conflict
 * with another request in flight on one of its items, or throttling. Up to three attempts in all. Any other
 * cancellation is the caller's to handle.
 *
 * @internal
 */
final class TransactionRetry
{
    private const int MAX_ATTEMPTS = 3;

    /**
     * A cancellation is retried only if every reason is one of these, and at least one is not `None`. `None` marks an
     * operation that did not cause the cancellation. A cancelled transaction applied none of its operations, so it can
     * be sent again as it is.
     *
     * @see TransactionCanceledException for the full list of cancellation reason codes.
     */
    private const array RETRYABLE_CODES = ['None', 'TransactionConflict', 'ThrottlingError', 'ProvisionedThroughputExceeded'];

    /**
     * @param-immediately-invoked-callable $send
     *
     * @param \Closure(): mixed $send - sends the transaction and waits for its result
     *
     * @throws TransactionCanceledException unless for a reason that passes, with attempts left
     */
    public static function run(\Closure $send): void
    {
        for ($attempt = 1;; ++$attempt) {
            try {
                $send();

                return;
            } catch (TransactionCanceledException $exception) {
                if ($attempt >= self::MAX_ATTEMPTS || !self::isTransient($exception)) {
                    throw $exception;
                }

                Backoff::wait($attempt);
            }
        }
    }

    /**
     * Whether the transaction was cancelled for reasons that pass: a conflict or throttling, and nothing else.
     */
    public static function isTransient(TransactionCanceledException $exception): bool
    {
        $codes = array_map(static fn (CancellationReason $reason): ?string => $reason->getCode(), $exception->getCancellationReasons());

        return array_any($codes, static fn (?string $code): bool => $code !== 'None')
            && array_all($codes, static fn (?string $code): bool => \in_array($code, self::RETRYABLE_CODES, true));
    }
}
