<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Definition;

use AsyncAws\DynamoDb\ValueObject\AttributeValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\DynamodbDalBundle\Definition\AttributeType;

#[CoversClass(AttributeType::class)]
class AttributeTypeTest extends TestCase
{
    /**
     * @return iterable<string, array{AttributeValue, AttributeType}>
     */
    public static function valuesOfEachType(): iterable
    {
        yield 'a string' => [new AttributeValue(['S' => 'a']), AttributeType::String];
        yield 'an empty string' => [new AttributeValue(['S' => '']), AttributeType::String];
        yield 'a number' => [new AttributeValue(['N' => '0']), AttributeType::Number];
        yield 'a binary' => [new AttributeValue(['B' => "\x00"]), AttributeType::Binary];
        yield 'a boolean false' => [new AttributeValue(['BOOL' => false]), AttributeType::Boolean];
        yield 'a string set' => [new AttributeValue(['SS' => ['a']]), AttributeType::StringSet];
        yield 'a number set' => [new AttributeValue(['NS' => ['1']]), AttributeType::NumberSet];
        yield 'a binary set' => [new AttributeValue(['BS' => ["\x00"]]), AttributeType::BinarySet];
        yield 'a list' => [new AttributeValue(['L' => [new AttributeValue(['S' => 'a'])]]), AttributeType::List];
        yield 'a map' => [new AttributeValue(['M' => ['a' => new AttributeValue(['S' => 'a'])]]), AttributeType::Map];
        // AsyncAws returns an empty array for every collection a value does not hold, so an empty one needs telling apart
        yield 'an empty list' => [new AttributeValue(['L' => []]), AttributeType::List];
        yield 'an empty map' => [new AttributeValue(['M' => []]), AttributeType::Map];
    }

    #[DataProvider('valuesOfEachType')]
    public function testTheTypeOfAValueIsTheOneItIsStoredAs(AttributeValue $value, AttributeType $type): void
    {
        static::assertSame($type, AttributeType::tryFromAttributeValue($value));
    }

    public function testNullHasNoType(): void
    {
        static::assertNull(AttributeType::tryFromAttributeValue(new AttributeValue(['NULL' => true])));
    }
}
