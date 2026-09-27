<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Client\Write;

use Shopware\DynamodbDalBundle\Client\Write\TransactionRetry;
use Shopware\DynamodbDalBundle\Test\ExceptionFactory;
use AsyncAws\DynamoDb\Exception\TransactionCanceledException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TransactionRetry::class)]
class TransactionRetryTest extends TestCase
{
    /**
     * `None` marks an operation that would have succeeded, so it does not stand in the way of a retry.
     */
    public function testAConflictBesideOperationsThatWouldHaveSucceededPasses(): void
    {
        static::assertTrue(TransactionRetry::isTransient(ExceptionFactory::transactionCanceled('TransactionConflict')));
        static::assertTrue(TransactionRetry::isTransient(ExceptionFactory::transactionCanceled('None', 'TransactionConflict')));
    }

    /**
     * A throttled transaction applied nothing, like one that conflicted, so it can be sent again as it is.
     */
    public function testThrottlingPasses(): void
    {
        static::assertTrue(TransactionRetry::isTransient(ExceptionFactory::transactionCanceled('None', 'ThrottlingError')));
        static::assertTrue(TransactionRetry::isTransient(ExceptionFactory::transactionCanceled('ProvisionedThroughputExceeded', 'TransactionConflict')));
    }

    /**
     * Sent again, a transaction whose condition failed fails again, so a conflict beside it does not make it retryable.
     */
    public function testAConflictBesideAnotherReasonDoesNotPass(): void
    {
        static::assertFalse(TransactionRetry::isTransient(ExceptionFactory::transactionCanceled('TransactionConflict', 'ConditionalCheckFailed')));
        static::assertFalse(TransactionRetry::isTransient(ExceptionFactory::transactionCanceled('ThrottlingError', 'ValidationError')));
    }

    public function testACancellationForNoReasonThatPassesDoesNotPass(): void
    {
        static::assertFalse(TransactionRetry::isTransient(ExceptionFactory::transactionCanceled('None', 'ConditionalCheckFailed')));
        static::assertFalse(TransactionRetry::isTransient(ExceptionFactory::transactionCanceled('None')));
        static::assertFalse(TransactionRetry::isTransient(ExceptionFactory::transactionCanceled()));
    }

    public function testRunSendsTheTransactionAgainAfterAConflict(): void
    {
        $attempts = 0;

        TransactionRetry::run(static function () use (&$attempts): void {
            if (++$attempts === 1) {
                throw ExceptionFactory::transactionCanceled('TransactionConflict');
            }
        });

        static::assertSame(2, $attempts);
    }

    public function testRunRethrowsTheConflictAfterThreeAttempts(): void
    {
        $attempts = 0;

        try {
            TransactionRetry::run(static function () use (&$attempts): void {
                ++$attempts;

                throw ExceptionFactory::transactionCanceled('TransactionConflict');
            });
            static::fail('The last conflict is rethrown');
        } catch (TransactionCanceledException) {
        }

        static::assertSame(3, $attempts);
    }

    public function testRunRethrowsAnyOtherCancellationAtOnce(): void
    {
        $attempts = 0;

        try {
            TransactionRetry::run(static function () use (&$attempts): void {
                ++$attempts;

                throw ExceptionFactory::transactionCanceled('ConditionalCheckFailed');
            });
            static::fail('The cancellation is rethrown');
        } catch (TransactionCanceledException) {
        }

        static::assertSame(1, $attempts);
    }
}
