<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Field;

use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\FieldDefinition;
use Shopware\DynamodbDalBundle\Definition\KeySchema;
use Shopware\DynamodbDalBundle\Exception\SerializerException;
use Shopware\DynamodbDalBundle\Serializer\Field\FloatFieldSerializer;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\NormalEntity;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FloatFieldSerializer::class)]
class FloatFieldSerializerTest extends TestCase
{
    private FloatFieldSerializer $serializer;

    protected function setUp(): void
    {
        $this->serializer = new FloatFieldSerializer();
    }

    public function testSupports(): void
    {
        static::assertTrue(FloatFieldSerializer::supports('float'));
        static::assertFalse(FloatFieldSerializer::supports('int'));
        static::assertFalse(FloatFieldSerializer::supports('string'));
    }

    public function testSerialize(): void
    {
        $expected = new AttributeValue(['N' => '12.34']);

        $definition = $this->fieldDefinition();
        $attribute = $this->serializer->serialize($definition, 12.34);

        static::assertEquals($expected, $attribute);
    }

    public function testSerializeWithFloatEdgeCase(): void
    {
        $expected = new AttributeValue(['N' => '129834.7849853986']);

        $definition = $this->fieldDefinition();
        $attribute = $this->serializer->serialize($definition, 1298.3478498539859 * 100);

        static::assertEquals($expected, $attribute);
    }

    public function testDeserialize(): void
    {
        $definition = $this->fieldDefinition();

        $attribute = AttributeValue::create(['N' => '12.34']);

        $result = $this->serializer->deserialize($definition, $attribute);

        static::assertIsFloat($result);
        static::assertSame(12.34, $result);
    }

    public function testDeserializeThrowsWhenNumberMissing(): void
    {
        $definition = $this->fieldDefinition();

        $attribute = AttributeValue::create(['S' => 'no-number']);

        $this->expectException(SerializerException::class);
        $this->expectExceptionMessage('Missing expected DynamoDB attribute value of type "N" for field "amount" in item "normal"');

        $this->serializer->deserialize($definition, $attribute);
    }

    /**
     * @return FieldDefinition<NormalEntity, 'float'>
     */
    private function fieldDefinition(): FieldDefinition
    {
        /** @var FieldDefinition<NormalEntity, 'float'> $fieldDefinition */
        $fieldDefinition = new FieldDefinition(
            'amount',
            'float',
            false,
            false,
            null,
            $this->serializer,
        );

        /** @var EntityDefinition<NormalEntity> $entityDefinition */
        $entityDefinition = new EntityDefinition(
            'normal',
            'normal',
            NormalEntity::class,
            null,
            [],
            new KeySchema('autofilledId'),
        );

        $fieldDefinition->setEntityDefinition($entityDefinition);

        return $fieldDefinition;
    }
}
