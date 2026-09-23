<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Client;

use Shopware\DynamodbDalBundle\Client\Cursor;
use Shopware\DynamodbDalBundle\Exception\InvalidCursorException;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Cursor::class)]
#[CoversClass(InvalidCursorException::class)]
class CursorTest extends TestCase
{
    public function testRoundTripsEveryKeyTypeAndTheDirection(): void
    {
        $key = [
            'tenantId' => new AttributeValue(['S' => 'tenant-1']),
            'createdAt' => new AttributeValue(['N' => '1700000000']),
            'hash' => new AttributeValue(['B' => "\x00\xff binary"]),
        ];

        $forward = Cursor::decode(new Cursor($key)->encode());
        $backward = Cursor::decode(new Cursor($key, backward: true)->encode());

        static::assertEquals($key, $forward->key);
        static::assertFalse($forward->backward);
        static::assertEquals($key, $backward->key);
        static::assertTrue($backward->backward);
    }

    public function testEncodesToAUrlSafeToken(): void
    {
        $token = new Cursor(['id' => new AttributeValue(['S' => str_repeat('?>~', 20)])])->encode();

        static::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $token);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidTokens(): iterable
    {
        $encode = static fn (mixed $payload): string => rtrim(strtr(base64_encode(json_encode($payload, \JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        yield 'not base64' => ['!!!'];
        yield 'not json' => [$encode('x') . 'garbage'];
        yield 'no key' => [$encode(['b' => true])];
        yield 'empty key' => [$encode(['k' => []])];
        yield 'list key' => [$encode(['k' => [['S' => 'x']]])];
        yield 'non-scalar type' => [$encode(['k' => ['id' => ['M' => []]]])];
        yield 'two types' => [$encode(['k' => ['id' => ['S' => 'x', 'N' => '1']]])];
        yield 'non-string value' => [$encode(['k' => ['id' => ['N' => 1]]])];
        yield 'invalid binary' => [$encode(['k' => ['id' => ['B' => '***']]])];
        yield 'non-bool direction' => [$encode(['k' => ['id' => ['S' => 'x']], 'b' => 'yes'])];
    }

    #[DataProvider('invalidTokens')]
    public function testRefusesATokenItDidNotProduce(string $token): void
    {
        $this->expectException(InvalidCursorException::class);

        Cursor::decode($token);
    }
}
