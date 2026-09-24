<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Field;

use Shopware\DynamodbDalBundle\Tests\Unit\Fixtures\OrderEntity;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\FieldDefinition;
use Shopware\DynamodbDalBundle\Definition\KeySchema;
use Shopware\DynamodbDalBundle\Exception\MissingAttributeValueException;
use Shopware\DynamodbDalBundle\Serializer\Field\BackedEnumFieldSerializer;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(BackedEnumFieldSerializer::class)]
class BackedEnumFieldSerializerTest extends TestCase
{
    private BackedEnumFieldSerializer $serializer;

    private FieldDefinition $definition;

    protected function setUp(): void
    {
        $this->serializer = new BackedEnumFieldSerializer();
        $this->definition = $this->createFieldDefinition();
    }

    public function testSupports(): void
    {
        static::assertTrue(BackedEnumFieldSerializer::supports(GoodEnum::class));
        static::assertFalse(BackedEnumFieldSerializer::supports(\BackedEnum::class));
        static::assertFalse(BackedEnumFieldSerializer::supports(\UnitEnum::class));
        static::assertFalse(BackedEnumFieldSerializer::supports('string'));
    }

    public function testSupportsEnumWithoutCasesThrowsException(): void
    {
        static::expectException(\LogicException::class);

        static::assertTrue(BackedEnumFieldSerializer::supports(EmptyEnum::class));
    }

    public function testSerialize(): void
    {
        $expected = new AttributeValue(['S' => '1']);

        $attribute = $this->serializer->serialize($this->definition, GoodEnum::A);

        static::assertEquals($expected, $attribute);
    }

    public function testDeserialize(): void
    {
        $attribute = AttributeValue::create(['S' => '2']);

        $result = $this->serializer->deserialize($this->definition, $attribute);

        static::assertInstanceOf(GoodEnum::class, $result);
        static::assertSame(GoodEnum::B, $result);
    }

    public function testDeserializeThrowsWhenStringMissing(): void
    {
        $attribute = AttributeValue::create(['N' => 'no-enum']);

        $this->expectException(MissingAttributeValueException::class);
        $this->expectExceptionMessage('Missing expected DynamoDB attribute value of type "S" for field "status" in item "order"');

        $this->serializer->deserialize($this->definition, $attribute);
    }

    public function testDeserializeThrowsWhenEnumCaseMissing(): void
    {
        $attribute = AttributeValue::create(['S' => 'no-enum']);

        $this->expectException(\ValueError::class);

        $this->serializer->deserialize($this->definition, $attribute);
    }

    public function testDeserializeThrowsForAValueThatIsNoLongerACaseOfAStringBackedEnum(): void
    {
        $definition = $this->createFieldDefinition(LabelEnum::class);

        $this->expectException(\ValueError::class);

        $this->serializer->deserialize($definition, AttributeValue::create(['S' => 'retired']));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonIntegerStrings(): iterable
    {
        yield 'a decimal' => ['1.5'];
        yield 'digits followed by text' => ['2abc'];
        yield 'text, which (int) makes 0' => ['abc'];
    }

    /**
     * `(int)` would read each of these as a case: 1, 2 and 0.
     */
    #[DataProvider('nonIntegerStrings')]
    public function testDeserializeThrowsForAStringThatIsNotExactlyAnIntegerOfAnIntBackedEnum(string $value): void
    {
        $definition = $this->createFieldDefinition(ZeroEnum::class);

        $this->expectExceptionObject(new \ValueError(\sprintf('"%s" is not a valid backing value for enum %s', $value, ZeroEnum::class)));

        $this->serializer->deserialize($definition, AttributeValue::create(['S' => $value]));
    }

    /**
     * @param class-string<\BackedEnum> $enum
     */
    private function createFieldDefinition(string $enum = GoodEnum::class): FieldDefinition
    {
        $definition = new FieldDefinition('status', $enum, false, false, null, $this->serializer);
        $definition->setEntityDefinition(new EntityDefinition('order', 'order', OrderEntity::class, null, [], new KeySchema('tenantId')));

        return $definition;
    }
}

enum GoodEnum: int
{
    case A = 1;
    case B = 2;
}

enum EmptyEnum: int
{
}

enum LabelEnum: string
{
    case Open = 'open';
}

enum ZeroEnum: int
{
    case None = 0;
    case One = 1;
    case Two = 2;
}
