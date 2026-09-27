<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Profiler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\DynamodbDalBundle\Profiler\DynamoDbRequest;

#[CoversClass(DynamoDbRequest::class)]
class DynamoDbRequestTest extends TestCase
{
    public function testTheOperationTableAndIndexAreReadOffTheRequest(): void
    {
        $request = DynamoDbRequest::fromTrace(self::trace('Query', ['TableName' => 'orders', 'IndexName' => 'byStatus'], 200, ['Count' => 0]));

        static::assertSame('Query', $request->operation);
        static::assertSame('orders', $request->table);
        static::assertSame('byStatus', $request->index);
        static::assertSame(['TableName' => 'orders', 'IndexName' => 'byStatus'], $request->payload);
    }

    /**
     * AsyncAws reads an error body with getContent(), so the trace holds it as a string
     */
    public function testAnErrorBodyReadAsAStringNamesTheError(): void
    {
        $request = DynamoDbRequest::fromTrace(self::trace('Query', ['TableName' => 't'], 400, '{"__type":"com.amazonaws.dynamodb.v20120810#ValidationException","message":"Invalid KeyConditionExpression"}'));

        static::assertSame('ValidationException: Invalid KeyConditionExpression', $request->error);
        static::assertNull($request->items);
    }

    public function testAnErrorWithoutABodyIsNamedByItsStatus(): void
    {
        $request = DynamoDbRequest::fromTrace(self::trace('PutItem', ['TableName' => 't'], 503, null));

        static::assertSame('HTTP 503', $request->error);
    }

    public function testADeeplyNestedPayloadStillNamesItsTable(): void
    {
        $value = ['S' => 'leaf'];
        for ($depth = 0; $depth < 20; ++$depth) {
            $value = ['M' => ['child' => $value]];
        }

        $request = DynamoDbRequest::fromTrace(self::trace('PutItem', ['TableName' => 't', 'Item' => ['data' => $value]], 200, null));

        static::assertSame('t', $request->table);
    }

    public function testABatchOrTransactionNamesTheTablesItSpans(): void
    {
        $batch = DynamoDbRequest::fromTrace(self::trace('BatchWriteItem', ['RequestItems' => ['orders' => [], 'archive' => []]], 200, null));
        $transaction = DynamoDbRequest::fromTrace(self::trace('TransactWriteItems', ['TransactItems' => [
            ['Put' => ['TableName' => 'orders']],
            ['Delete' => ['TableName' => 'archive']],
            ['Update' => ['TableName' => 'orders']],
        ]], 200, null));

        static::assertSame('orders, archive', $batch->table);
        static::assertSame('orders, archive', $transaction->table);
    }

    public function testABatchGetCountsTheItemsOfEveryTable(): void
    {
        $request = DynamoDbRequest::fromTrace(self::trace('BatchGetItem', [], 200, ['Responses' => [
            'orders' => [['id' => ['S' => 'a']], ['id' => ['S' => 'b']]],
            'archive' => [['id' => ['S' => 'c']]],
        ]]));

        static::assertSame(3, $request->items);
    }

    public function testACountingQueryCountsInsteadOfReturningItems(): void
    {
        $request = DynamoDbRequest::fromTrace(self::trace('Query', ['TableName' => 't', 'Select' => 'COUNT'], 200, ['Count' => 5]));

        static::assertNull($request->items);
        static::assertSame(5, $request->counted);
    }

    public function testAGetItemForAKeyWithoutAnItemReturnedNone(): void
    {
        static::assertSame(0, DynamoDbRequest::fromTrace(self::trace('GetItem', ['TableName' => 't'], 200, []))->items);
        static::assertSame(1, DynamoDbRequest::fromTrace(self::trace('GetItem', ['TableName' => 't'], 200, ['Item' => ['id' => ['S' => 'a']]]))->items);
    }

    public function testAResponseNobodyReadHasNoItemCount(): void
    {
        static::assertNull(DynamoDbRequest::fromTrace(self::trace('GetItem', ['TableName' => 't'], 200, null))->items);
    }

    public function testTheTargetIsReadFromARawHeaderLine(): void
    {
        $headers = ['Content-Type: application/x-amz-json-1.0', 'X-Amz-Target: DynamoDB_20120810.Scan'];

        static::assertTrue(DynamoDbRequest::isDynamoDb($headers));
        static::assertSame('Scan', DynamoDbRequest::fromTrace(['options' => ['headers' => $headers]])->operation);
    }

    public function testAnotherServiceIsNotDynamoDb(): void
    {
        static::assertFalse(DynamoDbRequest::isDynamoDb(['x-amz-target' => 'DynamoDBStreams_20120810.GetRecords']));
        static::assertFalse(DynamoDbRequest::isDynamoDb([]));
    }

    public function testTheStampIsReadOffTheExtraOption(): void
    {
        static::assertSame(7, DynamoDbRequest::stamp(['options' => ['extra' => [DynamoDbRequest::STAMP => 7]]]));
        static::assertNull(DynamoDbRequest::stamp(['options' => []]));
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<array-key, mixed>|string|null $content
     *
     * @return array<string, mixed>
     */
    private static function trace(string $operation, array $payload, int $httpCode, array|string|null $content): array
    {
        return [
            'options' => [
                'headers' => ['x-amz-target' => 'DynamoDB_20120810.' . $operation],
                'body' => json_encode($payload, \JSON_THROW_ON_ERROR),
            ],
            'info' => ['http_code' => $httpCode],
            'content' => $content,
        ];
    }
}
