<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Field;

use Shopware\DynamodbDalBundle\Tests\Unit\Fixtures\CustomerEntity;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\FieldDefinition;
use Shopware\DynamodbDalBundle\Definition\KeySchema;
use Shopware\DynamodbDalBundle\Exception\SerializerException;
use Shopware\DynamodbDalBundle\Serializer\Field\IntFieldSerializer;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IntFieldSerializer::class)]
class IntFieldSerializerTest extends TestCase
{
    private IntFieldSerializer $serializer;

    private FieldDefinition $definition;

    protected function setUp(): void
    {
        $this->serializer = new IntFieldSerializer();
        $this->definition = $this->createIntDefinition();
    }

    public function testSupports(): void
    {
        static::assertTrue(IntFieldSerializer::supports('int'));
        static::assertFalse(IntFieldSerializer::supports('float'));
        static::assertFalse(IntFieldSerializer::supports('string'));
    }

    public function testSerialize(): void
    {
        $expected = new AttributeValue(['N' => '12']);

        $attribute = $this->serializer->serialize($this->definition, 12);

        static::assertEquals($expected, $attribute);
    }

    public function testDeserialize(): void
    {
        $attribute = AttributeValue::create(['N' => '12']);

        $result = $this->serializer->deserialize($this->definition, $attribute);

        static::assertIsInt($result);
        static::assertSame(12, $result);
    }

    public function testDeserializeThrowsWhenNumberMissing(): void
    {
        $attribute = AttributeValue::create(['S' => 'no-number']);

        $this->expectException(SerializerException::class);
        $this->expectExceptionMessage('for field "counter" in item "customer"');

        $this->serializer->deserialize($this->definition, $attribute);
    }

    private function createIntDefinition(): FieldDefinition
    {
        $definition = new FieldDefinition('counter', 'int', false, true, 0, $this->serializer);
        $definition->setEntityDefinition(new EntityDefinition('customer', 'customer', CustomerEntity::class, null, [], new KeySchema('id')));

        return $definition;
    }
}
