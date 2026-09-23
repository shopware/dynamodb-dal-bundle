<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit;

use AsyncAws\Core\Result;
use AsyncAws\Core\Test\ResultMockFactory;
use AsyncAws\DynamoDb\Input\QueryInput;
use AsyncAws\DynamoDb\Input\ScanInput;
use AsyncAws\DynamoDb\Result\BatchGetItemOutput;
use AsyncAws\DynamoDb\Result\BatchWriteItemOutput;
use AsyncAws\DynamoDb\Result\GetItemOutput;
use AsyncAws\DynamoDb\Result\QueryOutput;
use AsyncAws\DynamoDb\Result\ScanOutput;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;
use AsyncAws\DynamoDb\ValueObject\KeysAndAttributes;
use AsyncAws\DynamoDb\ValueObject\WriteRequest;

trait DynamoDbResultTestTrait
{
    /**
     * @param list<array<string, AttributeValue>> $items
     * @param array<string, AttributeValue> $lastEvaluatedKey
     */
    protected static function queryOutput(array $items = [], array $lastEvaluatedKey = [], ?int $count = null): QueryOutput
    {
        $output = ResultMockFactory::create(QueryOutput::class, [
            'Items' => $items,
            'LastEvaluatedKey' => $lastEvaluatedKey,
            'Count' => $count,
        ]);

        self::injectDynamoDbInput($output, new QueryInput(['TableName' => 'test-table']));

        return $output;
    }

    /**
     * @param list<array<string, AttributeValue>> $items
     * @param array<string, AttributeValue> $lastEvaluatedKey
     */
    protected static function scanOutput(array $items = [], array $lastEvaluatedKey = [], ?int $count = null): ScanOutput
    {
        $output = ResultMockFactory::create(ScanOutput::class, [
            'Items' => $items,
            'LastEvaluatedKey' => $lastEvaluatedKey,
            'Count' => $count,
        ]);

        self::injectDynamoDbInput($output, new ScanInput(['TableName' => 'test-table']));

        return $output;
    }

    /**
     * @param array<string, AttributeValue> $item
     */
    protected static function getItemOutput(array $item = []): GetItemOutput
    {
        return ResultMockFactory::create(GetItemOutput::class, ['Item' => $item]);
    }

    /**
     * @param array<string, list<array<string, AttributeValue>>> $responses
     * @param array<string, KeysAndAttributes> $unprocessedKeys
     */
    protected static function batchGetItemOutput(array $responses = [], array $unprocessedKeys = []): BatchGetItemOutput
    {
        return ResultMockFactory::create(BatchGetItemOutput::class, [
            'Responses' => $responses,
            'UnprocessedKeys' => $unprocessedKeys,
        ]);
    }

    /**
     * @param array<string, list<WriteRequest>> $unprocessedItems
     */
    protected static function batchWriteItemOutput(array $unprocessedItems = []): BatchWriteItemOutput
    {
        return ResultMockFactory::create(BatchWriteItemOutput::class, ['UnprocessedItems' => $unprocessedItems]);
    }

    /**
     * @param array<string, string|int|float|bool|null> $values
     *
     * @return array<string, AttributeValue>
     */
    protected static function dynamoItem(array $values): array
    {
        $item = [];

        foreach ($values as $name => $value) {
            $item[$name] = match (true) {
                $value === null => new AttributeValue(['NULL' => true]),
                \is_bool($value) => new AttributeValue(['BOOL' => $value]),
                \is_int($value), \is_float($value) => new AttributeValue(['N' => (string) $value]),
                default => new AttributeValue(['S' => $value]),
            };
        }

        return $item;
    }

    // getItems() paginates through Result::$input; ResultMockFactory cannot fill it, it looks for "...Request" not async-aws's "...Input".
    private static function injectDynamoDbInput(Result $output, QueryInput|ScanInput $input): void
    {
        new \ReflectionProperty(Result::class, 'input')->setValue($output, $input);
    }
}
