<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Expression;

use Shopware\DynamodbDalBundle\Expression\ExpressionCompiledResult;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExpressionCompiledResult::class)]
class ExpressionCompiledResultTest extends TestCase
{
    public function testEmptyDefaults(): void
    {
        $result = new ExpressionCompiledResult();

        static::assertNull($result->expression);
        static::assertSame([], $result->names);
        static::assertSame([], $result->values);
    }

    public function testGetExpressionReturnsEmptyWhenNoExpression(): void
    {
        $empty = new ExpressionCompiledResult();

        static::assertSame([], $empty->getExpression('filter'));
        static::assertSame([], $empty->getExpression('key-condition'));
    }

    public function testGetExpressionForFilter(): void
    {
        $result = new ExpressionCompiledResult('#name = :name_0');

        static::assertSame(
            ['FilterExpression' => '#name = :name_0'],
            $result->getExpression('filter'),
        );
    }

    public function testGetExpressionForKeyCondition(): void
    {
        $result = new ExpressionCompiledResult('#id = :id_0');

        static::assertSame(
            ['KeyConditionExpression' => '#id = :id_0'],
            $result->getExpression('key-condition'),
        );
    }

    public function testGetExpressionAttributesReturnsBothMapsForAPopulatedResult(): void
    {
        $result = new ExpressionCompiledResult(
            '#name = :name_0',
            ['#name' => 'name'],
            [':name_0' => new AttributeValue(['S' => 'foo'])],
        );

        static::assertEquals(
            [
                'ExpressionAttributeNames' => ['#name' => 'name'],
                'ExpressionAttributeValues' => [':name_0' => new AttributeValue(['S' => 'foo'])],
            ],
            $result->getExpressionAttributes(),
        );
    }

    public function testGetExpressionAttributesOmitsValuesWhenEmpty(): void
    {
        // exists()-style filters produce names but no values.
        $result = new ExpressionCompiledResult('attribute_exists(#name)', ['#name' => 'name']);

        static::assertSame(
            ['ExpressionAttributeNames' => ['#name' => 'name']],
            $result->getExpressionAttributes(),
        );
    }

    public function testGetExpressionAttributesEmptyWhenNothingRegistered(): void
    {
        static::assertSame([], new ExpressionCompiledResult()->getExpressionAttributes());
    }

    public function testGetExpressionAttributesUnionsMapsFromOthers(): void
    {
        $key = new ExpressionCompiledResult(
            '#id = :id_0',
            ['#id' => 'id'],
            [':id_0' => new AttributeValue(['S' => 'tenant-1'])],
        );
        $filter = new ExpressionCompiledResult(
            '#status = :status_1',
            ['#status' => 'status'],
            [':status_1' => new AttributeValue(['S' => 'active'])],
        );

        static::assertEquals(
            [
                'ExpressionAttributeNames' => ['#id' => 'id', '#status' => 'status'],
                'ExpressionAttributeValues' => [
                    ':id_0' => new AttributeValue(['S' => 'tenant-1']),
                    ':status_1' => new AttributeValue(['S' => 'active']),
                ],
            ],
            $key->getExpressionAttributes($filter),
        );
    }

    public function testGetExpressionAttributesFollowsArrayMergeSemanticsLastWins(): void
    {
        // PHP's array_merge — for string keys, the later value overwrites earlier ones.
        $a = new ExpressionCompiledResult(
            '#x = :x_0',
            ['#x' => 'x'],
            [':x_0' => new AttributeValue(['S' => 'a'])],
        );
        $b = new ExpressionCompiledResult(
            '#x = :x_0',
            ['#x' => 'overridden'],
            [':x_0' => new AttributeValue(['S' => 'b'])],
        );

        static::assertEquals(
            [
                'ExpressionAttributeNames' => ['#x' => 'overridden'],
                'ExpressionAttributeValues' => [':x_0' => new AttributeValue(['S' => 'b'])],
            ],
            $a->getExpressionAttributes($b),
        );
    }

    public function testGetExpressionAttributesAcceptsMultipleResults(): void
    {
        $a = new ExpressionCompiledResult('#a = :a_0', ['#a' => 'a'], [':a_0' => new AttributeValue(['S' => '1'])]);
        $b = new ExpressionCompiledResult('#b = :b_1', ['#b' => 'b'], [':b_1' => new AttributeValue(['S' => '2'])]);
        $c = new ExpressionCompiledResult('#c = :c_2', ['#c' => 'c'], [':c_2' => new AttributeValue(['S' => '3'])]);

        $merged = $a->getExpressionAttributes($b, $c);

        static::assertSame(['#a' => 'a', '#b' => 'b', '#c' => 'c'], $merged['ExpressionAttributeNames'] ?? null);
        static::assertCount(3, $merged['ExpressionAttributeValues'] ?? []);
    }

    public function testGetExpressionAttributesDoesNotMutateInputs(): void
    {
        $a = new ExpressionCompiledResult('#a = :a_0', ['#a' => 'a'], [':a_0' => new AttributeValue(['S' => '1'])]);
        $b = new ExpressionCompiledResult('#b = :b_1', ['#b' => 'b'], [':b_1' => new AttributeValue(['S' => '2'])]);

        $a->getExpressionAttributes($b);

        // Originals untouched.
        static::assertSame(['#a' => 'a'], $a->names);
        static::assertSame(['#b' => 'b'], $b->names);
    }
}
