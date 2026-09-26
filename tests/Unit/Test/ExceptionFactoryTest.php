<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Test;

use Shopware\DynamodbDalBundle\Test\ExceptionFactory;
use AsyncAws\DynamoDb\ValueObject\CancellationReason;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExceptionFactory::class)]
class ExceptionFactoryTest extends TestCase
{
    public function testBuildsTheExceptionOfAFailedCondition(): void
    {
        $exception = ExceptionFactory::conditionalCheckFailed();

        static::assertSame('ConditionalCheckFailedException', $exception->getAwsCode());
        static::assertSame(400, $exception->getCode());
    }

    public function testBuildsACancelledTransactionWithOneReasonPerOperationInOrder(): void
    {
        $exception = ExceptionFactory::transactionCanceled('None', 'ConditionalCheckFailed', 'None');

        static::assertSame('TransactionCanceledException', $exception->getAwsCode());
        static::assertSame(
            ['None', 'ConditionalCheckFailed', 'None'],
            array_map(static fn (CancellationReason $reason): ?string => $reason->getCode(), $exception->getCancellationReasons()),
        );
    }
}
