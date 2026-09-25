<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Test;

use AsyncAws\Core\AwsError\AwsError;
use AsyncAws\Core\Test\Http\SimpleMockedResponse;
use AsyncAws\DynamoDb\Exception\ConditionalCheckFailedException;
use AsyncAws\DynamoDb\Exception\TransactionCanceledException;

/**
 * Builds the DynamoDB exceptions a write passes through unwrapped, for a test double of the client to throw. The
 * exceptions of the bundle itself take the definition they name, which {@see EntityDefinitionFactory} builds:
 * `new ConditionEmptyException(EntityDefinitionFactory::create(OrderEntity::class))`.
 */
final class ExceptionFactory
{
    private function __construct()
    {
    }

    /**
     * What a single put, update or delete throws where its condition fails, or where an updated item does not exist.
     */
    public static function conditionalCheckFailed(): ConditionalCheckFailedException
    {
        return new ConditionalCheckFailedException(
            self::response(['message' => 'The conditional request failed']),
            new AwsError('ConditionalCheckFailedException', 'The conditional request failed', 'Client', null),
        );
    }

    /**
     * What a transaction throws where DynamoDB cancels it, with one cancellation reason per operation, in the order
     * the operations were given: `None` for one that would have succeeded, `ConditionalCheckFailed` for one whose
     * condition failed or whose updated item does not exist, `TransactionConflict`, and so on.
     */
    public static function transactionCanceled(string ...$reasonCodes): TransactionCanceledException
    {
        $message = \sprintf('Transaction cancelled, please refer cancellation reasons for specific reasons [%s]', implode(', ', $reasonCodes));

        return new TransactionCanceledException(
            self::response([
                'message' => $message,
                'CancellationReasons' => array_map(static fn (string $code): array => ['Code' => $code], array_values($reasonCodes)),
            ]),
            new AwsError('TransactionCanceledException', $message, 'Client', null),
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function response(array $body): SimpleMockedResponse
    {
        return new SimpleMockedResponse(json_encode($body, \JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}', ['content-type' => 'application/x-amz-json-1.0'], 400);
    }
}
