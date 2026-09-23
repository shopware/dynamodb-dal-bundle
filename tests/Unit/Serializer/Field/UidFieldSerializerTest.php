<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Field;

use Shopware\DynamodbDalBundle\Tests\Unit\Fixtures\CustomerEntity;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\FieldDefinition;
use Shopware\DynamodbDalBundle\Definition\KeySchema;
use Shopware\DynamodbDalBundle\Exception\SerializerException;
use Shopware\DynamodbDalBundle\Serializer\Field\UidFieldSerializer;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\AbstractUid;
use Symfony\Component\Uid\Exception\InvalidArgumentException;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

#[CoversClass(UidFieldSerializer::class)]
class UidFieldSerializerTest extends TestCase
{
    private const string ENTITY_NAME = 'test_entity';

    private const string FIELD_NAME = 'id';

    private UidFieldSerializer $serializer;

    private FieldDefinition $definition;

    protected function setUp(): void
    {
        $this->serializer = new UidFieldSerializer();
        $this->definition = $this->createUidDefinition();
    }

    public function testSupports(): void
    {
        static::assertTrue(UidFieldSerializer::supports(Uuid::class));
        static::assertTrue(UidFieldSerializer::supports(UuidV7::class));
        static::assertFalse(UidFieldSerializer::supports(AbstractUid::class));
        static::assertFalse(UidFieldSerializer::supports('string'));
        static::assertFalse(UidFieldSerializer::supports('int'));
        static::assertFalse(UidFieldSerializer::supports('float'));
    }

    public function testSerialize(): void
    {
        $value = Uuid::v7();
        $expected = new AttributeValue(['S' => $value->toString()]);

        $attribute = $this->serializer->serialize($this->definition, $value);

        static::assertEquals($expected, $attribute);
    }

    public function testDeserialize(): void
    {
        $expected = Uuid::v7();

        $attribute = AttributeValue::create(['S' => $expected->toString()]);

        $result = $this->serializer->deserialize($this->definition, $attribute);

        static::assertInstanceOf(UuidV7::class, $result);
        static::assertEquals($expected, $result);
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

    public function testDeserializeThrowsWhenInvalidUuid(): void
    {
        $attribute = AttributeValue::create(['S' => 'no-uuid']);

        $this->expectException(InvalidArgumentException::class);

        $this->serializer->deserialize($this->definition, $attribute);
    }

    private function createUidDefinition(): FieldDefinition
    {
        $definition = new FieldDefinition(self::FIELD_NAME, UuidV7::class, false, false, null, $this->serializer);
        $definition->setEntityDefinition(new EntityDefinition(self::ENTITY_NAME, self::ENTITY_NAME, CustomerEntity::class, null, [], new KeySchema(self::FIELD_NAME)));

        return $definition;
    }
}
