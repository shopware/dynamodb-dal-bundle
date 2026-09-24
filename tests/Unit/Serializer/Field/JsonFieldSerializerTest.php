<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Field;

use Shopware\DynamodbDalBundle\Tests\Unit\Fixtures\CustomerEntity;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\FieldDefinition;
use Shopware\DynamodbDalBundle\Definition\KeySchema;
use Shopware\DynamodbDalBundle\Exception\MissingAttributeValueException;
use Shopware\DynamodbDalBundle\Exception\WrongTypeException;
use Shopware\DynamodbDalBundle\Serializer\Field\JsonFieldSerializer;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(JsonFieldSerializer::class)]
class JsonFieldSerializerTest extends TestCase
{
    private const string ENTITY_NAME = 'test_entity';

    private const string FIELD_NAME = 'payload';

    private JsonFieldSerializer $serializer;

    private FieldDefinition $definition;

    protected function setUp(): void
    {
        $this->serializer = new JsonFieldSerializer();
        $this->definition = $this->createJsonDefinition();
    }

    public function testSupports(): void
    {
        static::assertTrue(JsonFieldSerializer::supports('array'));
        static::assertTrue(JsonFieldSerializer::supports($this->jsonSerializable([])::class));

        // A `@var` type makes it a list or map field, which the collection serializers own.
        static::assertFalse(JsonFieldSerializer::supports('array', 'list<string>'));
        static::assertFalse(JsonFieldSerializer::supports('array', 'array<string, int>'));
        static::assertFalse(JsonFieldSerializer::supports($this->jsonSerializable([])::class, 'list<string>'));

        // A Uid is JsonSerializable too, but has a serializer of its own.
        static::assertFalse(JsonFieldSerializer::supports(Uuid::class));

        static::assertFalse(JsonFieldSerializer::supports('string'));
        static::assertFalse(JsonFieldSerializer::supports(\stdClass::class));
    }

    public function testSerialize(): void
    {
        $attribute = $this->serializer->serialize($this->definition, ['a' => 1, 'nested' => ['b' => true], 'list' => ['x', 'y']]);

        static::assertEquals(AttributeValue::create(['S' => '{"a":1,"nested":{"b":true},"list":["x","y"]}']), $attribute);
    }

    public function testSerializeAJsonSerializableStoresWhatItSerializesTo(): void
    {
        $attribute = $this->serializer->serialize($this->definition, $this->jsonSerializable(['street' => 'Main Street 1']));

        static::assertEquals(AttributeValue::create(['S' => '{"street":"Main Street 1"}']), $attribute);
    }

    /**
     * Only an array reads back, so a JsonSerializable that serializes to anything else is refused.
     */
    public function testSerializeThrowsWhenAJsonSerializableDoesNotSerializeToAnArray(): void
    {
        $this->expectException(WrongTypeException::class);
        $this->expectExceptionMessage('Expected type "array|\JsonSerializable" for field "payload" in item "test_entity", got "string"');

        $this->serializer->serialize($this->definition, $this->jsonSerializable('scalar'));
    }

    public function testSerializeThrowsWhenValueIsNeitherArrayNorJsonSerializable(): void
    {
        $this->expectException(WrongTypeException::class);
        $this->expectExceptionMessage('Expected type "array|\JsonSerializable" for field "payload" in item "test_entity", got "string"');

        $this->serializer->serialize($this->definition, 'not-an-array');
    }

    public function testDeserialize(): void
    {
        $result = $this->serializer->deserialize($this->definition, AttributeValue::create(['S' => '{"a":1,"nested":{"b":true},"list":["x","y"]}']));

        static::assertSame(['a' => 1, 'nested' => ['b' => true], 'list' => ['x', 'y']], $result);
    }

    /**
     * The serializer has no way back to the object, so a JsonSerializable property reads as the decoded
     * array — for a normalizer to turn into the object.
     */
    public function testDeserializeAJsonSerializableFieldReturnsTheDecodedArray(): void
    {
        $definition = $this->createJsonDefinition($this->jsonSerializable([])::class);

        $result = $this->serializer->deserialize($definition, AttributeValue::create(['S' => '{"street":"Main Street 1"}']));

        static::assertSame(['street' => 'Main Street 1'], $result);
    }

    public function testDeserializeThrowsWhenStringMissing(): void
    {
        $this->expectException(MissingAttributeValueException::class);
        $this->expectExceptionMessage(\sprintf(
            'Missing expected DynamoDB attribute value of type "S" for field "%s" in item "%s"',
            self::FIELD_NAME,
            self::ENTITY_NAME,
        ));

        $this->serializer->deserialize($this->definition, AttributeValue::create(['N' => '1']));
    }

    public function testDeserializeThrowsWhenTheJsonIsNoArray(): void
    {
        $this->expectException(WrongTypeException::class);
        $this->expectExceptionMessage('Expected type "array" for field "payload" in item "test_entity", got "integer"');

        $this->serializer->deserialize($this->definition, AttributeValue::create(['S' => '42']));
    }

    public function testDeserializeThrowsOnInvalidJson(): void
    {
        $this->expectException(\JsonException::class);

        $this->serializer->deserialize($this->definition, AttributeValue::create(['S' => '{not json']));
    }

    private function jsonSerializable(mixed $serialized): \JsonSerializable
    {
        return new readonly class($serialized) implements \JsonSerializable {
            public function __construct(
                private mixed $serialized,
            ) {
            }

            public function jsonSerialize(): mixed
            {
                return $this->serialized;
            }
        };
    }

    private function createJsonDefinition(string $type = 'array'): FieldDefinition
    {
        $definition = new FieldDefinition(self::FIELD_NAME, $type, false, false, null, $this->serializer);
        $definition->setEntityDefinition(new EntityDefinition(self::ENTITY_NAME, self::ENTITY_NAME, CustomerEntity::class, null, [], new KeySchema('id')));

        return $definition;
    }
}
