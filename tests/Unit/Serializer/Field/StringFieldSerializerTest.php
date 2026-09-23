<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Field;

use Shopware\DynamodbDalBundle\Tests\Unit\Fixtures\CustomerEntity;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\FieldDefinition;
use Shopware\DynamodbDalBundle\Definition\KeySchema;
use Shopware\DynamodbDalBundle\Exception\SerializerException;
use Shopware\DynamodbDalBundle\Serializer\Field\StringFieldSerializer;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StringFieldSerializer::class)]
class StringFieldSerializerTest extends TestCase
{
    private const string ENTITY_NAME = 'test_entity';

    private const string FIELD_NAME = 'body';

    private StringFieldSerializer $serializer;

    private FieldDefinition $definition;

    protected function setUp(): void
    {
        $this->serializer = new StringFieldSerializer();
        $this->definition = $this->createStringDefinition();
    }

    public function testSupports(): void
    {
        static::assertTrue(StringFieldSerializer::supports('string'));
        static::assertFalse(StringFieldSerializer::supports('int'));
        static::assertFalse(StringFieldSerializer::supports('float'));
    }

    public function testSerialize(): void
    {
        $expected = new AttributeValue(['S' => 'string']);

        $attribute = $this->serializer->serialize($this->definition, 'string');

        static::assertEquals($expected, $attribute);
    }

    public function testDeserialize(): void
    {
        $attribute = AttributeValue::create(['S' => 'string']);

        $result = $this->serializer->deserialize($this->definition, $attribute);

        static::assertIsString($result);
        static::assertSame('string', $result);
    }

    public function testDeserializeThrowsWhenStringMissing(): void
    {
        $attribute = AttributeValue::create(['N' => 'no-string']);

        $this->expectException(SerializerException::class);
        $this->expectExceptionMessage(\sprintf(
            'Missing expected DynamoDB attribute value of type "S" for field "%s" in item "%s"',
            self::FIELD_NAME,
            self::ENTITY_NAME,
        ));

        $this->serializer->deserialize($this->definition, $attribute);
    }

    private function createStringDefinition(): FieldDefinition
    {
        $definition = new FieldDefinition(self::FIELD_NAME, 'string', false, false, null, $this->serializer);
        $definition->setEntityDefinition(new EntityDefinition(self::ENTITY_NAME, self::ENTITY_NAME, CustomerEntity::class, null, [], new KeySchema('id')));

        return $definition;
    }
}
