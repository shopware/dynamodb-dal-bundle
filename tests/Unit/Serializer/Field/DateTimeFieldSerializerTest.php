<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Field;

use Shopware\DynamodbDalBundle\Tests\Unit\Fixtures\CustomerEntity;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\FieldDefinition;
use Shopware\DynamodbDalBundle\Definition\KeySchema;
use Shopware\DynamodbDalBundle\Exception\MissingAttributeValueException;
use Shopware\DynamodbDalBundle\Serializer\Field\DateTimeFieldSerializer;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\DatePoint;

#[CoversClass(DateTimeFieldSerializer::class)]
class DateTimeFieldSerializerTest extends TestCase
{
    private const string ENTITY_NAME = 'customer';

    private const string FIELD_NAME = 'createdAt';

    private DateTimeFieldSerializer $serializer;

    private FieldDefinition $definition;

    protected function setUp(): void
    {
        $this->serializer = new DateTimeFieldSerializer();
        $this->definition = $this->createDateTimeDefinition();
    }

    public function testSupports(): void
    {
        static::assertTrue(DateTimeFieldSerializer::supports(\DateTime::class));
        static::assertTrue(DateTimeFieldSerializer::supports(\DateTimeImmutable::class));
        static::assertFalse(DateTimeFieldSerializer::supports(\DateTimeInterface::class));
        static::assertFalse(DateTimeFieldSerializer::supports(DatePoint::class));
        static::assertFalse(DateTimeFieldSerializer::supports('string'));
    }

    public function testSerialize(): void
    {
        $expected = new AttributeValue(['N' => '1']);

        $attribute = $this->serializer->serialize($this->definition, new \DateTime('@1'));

        static::assertEquals($expected, $attribute);
    }

    public function testDeserializeFromNumber(): void
    {
        $attribute = AttributeValue::create(['N' => '1']);

        $result = $this->serializer->deserialize($this->definition, $attribute);

        static::assertInstanceOf(\DateTimeImmutable::class, $result);
        static::assertEquals(new \DateTimeImmutable('@1'), $result);
    }

    public function testDeserializeFromStringLegacy(): void
    {
        $attribute = AttributeValue::create(['S' => '1970-01-01T00:00:01+00:00']);

        $result = $this->serializer->deserialize($this->definition, $attribute);

        static::assertInstanceOf(\DateTimeImmutable::class, $result);
        static::assertEquals(new \DateTimeImmutable('@1'), $result);
    }

    public function testDeserializeThrowsWhenValueMissing(): void
    {
        $attribute = AttributeValue::create(['BOOL' => true]);

        $this->expectException(MissingAttributeValueException::class);
        $this->expectExceptionMessage(\sprintf(
            'Missing expected DynamoDB attribute value of type "N" for field "%s" in item "%s"',
            self::FIELD_NAME,
            self::ENTITY_NAME,
        ));

        $this->serializer->deserialize($this->definition, $attribute);
    }

    private function createDateTimeDefinition(): FieldDefinition
    {
        $definition = new FieldDefinition(self::FIELD_NAME, \DateTimeImmutable::class, false, false, null, $this->serializer);
        $definition->setEntityDefinition(new EntityDefinition(self::ENTITY_NAME, self::ENTITY_NAME, CustomerEntity::class, null, [], new KeySchema('id')));

        return $definition;
    }
}
