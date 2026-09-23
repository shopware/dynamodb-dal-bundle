<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Field;

use Shopware\DynamodbDalBundle\Tests\Unit\Fixtures\OrderEntity;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\FieldDefinition;
use Shopware\DynamodbDalBundle\Definition\KeySchema;
use Shopware\DynamodbDalBundle\Exception\MissingAttributeValueException;
use Shopware\DynamodbDalBundle\Serializer\Field\BoolFieldSerializer;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BoolFieldSerializer::class)]
class BoolFieldSerializerTest extends TestCase
{
    private BoolFieldSerializer $serializer;

    private FieldDefinition $definition;

    protected function setUp(): void
    {
        $this->serializer = new BoolFieldSerializer();
        $this->definition = $this->createFieldDefinition();
    }

    public function testSupports(): void
    {
        static::assertTrue(BoolFieldSerializer::supports('bool'));
        static::assertFalse(BoolFieldSerializer::supports('float'));
        static::assertFalse(BoolFieldSerializer::supports('int'));
        static::assertFalse(BoolFieldSerializer::supports('string'));
    }

    public function testSerializeTrue(): void
    {
        $expected = new AttributeValue(['BOOL' => true]);

        $attribute = $this->serializer->serialize($this->definition, true);

        static::assertEquals($expected, $attribute);
    }

    public function testSerializeFalse(): void
    {
        $expected = new AttributeValue(['BOOL' => false]);

        $attribute = $this->serializer->serialize($this->definition, false);

        static::assertEquals($expected, $attribute);
    }

    public function testDeserializeTrue(): void
    {
        $attribute = AttributeValue::create(['BOOL' => true]);

        $result = $this->serializer->deserialize($this->definition, $attribute);

        static::assertIsBool($result);
        static::assertTrue($result);
    }

    public function testDeserializeFalse(): void
    {
        $attribute = AttributeValue::create(['BOOL' => false]);

        $result = $this->serializer->deserialize($this->definition, $attribute);

        static::assertIsBool($result);
        static::assertFalse($result);
    }

    public function testDeserializeThrowsWhenBoolMissing(): void
    {
        $attribute = AttributeValue::create(['S' => 'no-bool']);

        $this->expectException(MissingAttributeValueException::class);
        $this->expectExceptionMessage('Missing expected DynamoDB attribute value of type "BOOL" for field "active" in item "order"');

        $this->serializer->deserialize($this->definition, $attribute);
    }

    private function createFieldDefinition(): FieldDefinition
    {
        $definition = new FieldDefinition('active', 'bool', false, true, false, $this->serializer);
        $definition->setEntityDefinition(new EntityDefinition('order', 'order', OrderEntity::class, null, [], new KeySchema('id')));

        return $definition;
    }
}
