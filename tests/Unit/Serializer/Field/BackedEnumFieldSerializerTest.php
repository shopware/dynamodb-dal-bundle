<?php declare(strict_types=1); // @phpstan-ignore symplify.multipleClassLikeInFile

namespace Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Field;

use Shopware\DynamodbDalBundle\Tests\Unit\Fixtures\OrderEntity;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\FieldDefinition;
use Shopware\DynamodbDalBundle\Definition\KeySchema;
use Shopware\DynamodbDalBundle\Exception\MissingAttributeValueException;
use Shopware\DynamodbDalBundle\Serializer\Field\BackedEnumFieldSerializer;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;
use PHPUnit\Framework\Attributes\CoversClass;
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

    private function createFieldDefinition(): FieldDefinition
    {
        $definition = new FieldDefinition('status', GoodEnum::class, false, false, null, $this->serializer);
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
