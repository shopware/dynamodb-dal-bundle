<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Field;

use Shopware\DynamodbDalBundle\Tests\Unit\Fixtures\CustomerEntity;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\FieldDefinition;
use Shopware\DynamodbDalBundle\Definition\KeySchema;
use Shopware\DynamodbDalBundle\Exception\FieldDeserializationException;
use Shopware\DynamodbDalBundle\Exception\FieldSerializationException;
use Shopware\DynamodbDalBundle\Exception\MissingAttributeValueException;
use Shopware\DynamodbDalBundle\Exception\WrongTypeException;
use Shopware\DynamodbDalBundle\Serializer\Field\AbstractFieldSerializer;
use Shopware\DynamodbDalBundle\Serializer\Field\ListFieldSerializer;
use Shopware\DynamodbDalBundle\Serializer\Field\MapFieldSerializer;
use Shopware\DynamodbDalBundle\Serializer\Field\StringFieldSerializer;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MapFieldSerializer::class)]
class MapFieldSerializerTest extends TestCase
{
    private MapFieldSerializer $serializer;

    protected function setUp(): void
    {
        $this->serializer = new MapFieldSerializer();
    }

    public function testSupports(): void
    {
        static::assertTrue(MapFieldSerializer::supports('array', 'array<string, string>'));
        static::assertTrue(MapFieldSerializer::supports('array', 'array<string,int>'));
        static::assertFalse(MapFieldSerializer::supports('array', null));
        static::assertFalse(MapFieldSerializer::supports('array', 'list<string>'));
        static::assertFalse(MapFieldSerializer::supports('string', 'array<string, string>'));
    }

    public function testSerializeUsesValueSerializer(): void
    {
        $definition = $this->createMapDefinition(new StringFieldSerializer());

        $value = ['foo' => 'bar', 'baz' => 'qux'];
        $attribute = $this->serializer->serialize($definition, $value);

        static::assertEquals(
            AttributeValue::create([
                'M' => [
                    'foo' => ['S' => 'bar'],
                    'baz' => ['S' => 'qux'],
                ],
            ]),
            $attribute,
        );
    }

    public function testSerializeStoresNullAsDynamoNull(): void
    {
        $definition = $this->createMapDefinition(new StringFieldSerializer());

        $value = ['present' => 'ok', 'absent' => null];
        $attribute = $this->serializer->serialize($definition, $value);

        static::assertEquals(
            AttributeValue::create([
                'M' => [
                    'present' => ['S' => 'ok'],
                    'absent' => ['NULL' => true],
                ],
            ]),
            $attribute,
        );
    }

    public function testSerializeEmptyArray(): void
    {
        $definition = $this->createMapDefinition(new StringFieldSerializer());

        $attribute = $this->serializer->serialize($definition, []);

        static::assertEquals(AttributeValue::create(['M' => []]), $attribute);
    }

    public function testSerializeThrowsWhenValueTypeMissing(): void
    {
        $definition = $this->createMapDefinition();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Map field "meta" must have valueType set so values can be serialized');

        $this->serializer->serialize($definition, ['a' => 'b']);
    }

    public function testSerializeThrowsWhenValueIsNotArray(): void
    {
        $definition = $this->createMapDefinition(new StringFieldSerializer());

        $this->expectException(WrongTypeException::class);
        $this->expectExceptionMessage('Expected type "array" for field "meta" in item "test_entity", got "string"');

        /** @phpstan-ignore-next-line argument.type (intentional wrong type to trigger exception) */
        $this->serializer->serialize($definition, 'not-an-array');
    }

    public function testDeserializeUsesValueSerializer(): void
    {
        $definition = $this->createMapDefinition(new StringFieldSerializer());

        $attribute = AttributeValue::create([
            'M' => [
                'a' => ['S' => 'x'],
                'b' => ['S' => 'y'],
            ],
        ]);

        $result = $this->serializer->deserialize($definition, $attribute);

        static::assertIsArray($result);
        static::assertSame(['a' => 'x', 'b' => 'y'], $result);
    }

    public function testDeserializeMapsDynamoNullToPhpNull(): void
    {
        $definition = $this->createMapDefinition(new StringFieldSerializer());

        $attribute = AttributeValue::create([
            'M' => [
                'a' => ['S' => 'x'],
                'b' => ['NULL' => true],
            ],
        ]);

        $result = $this->serializer->deserialize($definition, $attribute);

        static::assertSame(['a' => 'x', 'b' => null], $result);
    }

    public function testDeserializeEmptyMap(): void
    {
        $definition = $this->createMapDefinition(new StringFieldSerializer());

        $attribute = AttributeValue::create(['M' => []]);

        $result = $this->serializer->deserialize($definition, $attribute);

        static::assertSame([], $result);
    }

    public function testDeserializeThrowsWhenMapMissing(): void
    {
        $definition = $this->createMapDefinition(new StringFieldSerializer());

        $attribute = AttributeValue::create(['S' => 'not-a-map']);

        $this->expectException(MissingAttributeValueException::class);
        $this->expectExceptionMessage('Missing expected DynamoDB attribute value of type "M" for field "meta" in item "test_entity"');

        $this->serializer->deserialize($definition, $attribute);
    }

    public function testDeserializeThrowsWhenValueTypeMissing(): void
    {
        $definition = $this->createMapDefinition();

        $attribute = AttributeValue::create(['M' => ['k' => ['S' => 'v']]]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Map field "meta" must have valueType set so values can be deserialized');

        $this->serializer->deserialize($definition, $attribute);
    }

    public function testSerializeWrapsANestedFailureWithItsPath(): void
    {
        $definition = $this->createMapDefinition(new class extends AbstractFieldSerializer {
            public static function supports(string $type, ?string $docblockType = null): bool
            {
                return $type === 'string';
            }

            public function serialize(FieldDefinition $definition, mixed $value): AttributeValue
            {
                throw new FieldSerializationException($definition, path: 'inner');
            }

            public function deserialize(FieldDefinition $definition, AttributeValue $attributeValue): mixed
            {
                return $attributeValue->getS();
            }
        });

        try {
            $this->serializer->serialize($definition, ['outer' => 'value']);
            static::fail('Expected a FieldSerializationException to be thrown.');
        } catch (FieldSerializationException $e) {
            static::assertSame('meta.outer.inner', $e->path);
        }
    }

    public function testDeserializeWrapsANestedFailureWithItsPath(): void
    {
        $definition = $this->createMapDefinition(new class extends StringFieldSerializer {
            public function deserialize(FieldDefinition $definition, AttributeValue $attributeValue): mixed
            {
                throw new FieldDeserializationException($definition, path: 'inner');
            }
        });

        try {
            $this->serializer->deserialize($definition, AttributeValue::create(['M' => ['outer' => ['S' => 'value']]]));
            static::fail('Expected a FieldDeserializationException to be thrown.');
        } catch (FieldDeserializationException $e) {
            static::assertSame('meta.outer.inner', $e->path);
        }
    }

    /**
     * Map of lists: array<string, list<string>>
     */
    /**
     * Every level adds the element it was on, so the path addresses the value that actually failed —
     * the entry under `second`, its second element — rather than the map field around it.
     */
    public function testAFailureInAMapOfListsIsPathedToTheElement(): void
    {
        $definition = $this->createMapOfListsDefinition();

        try {
            $this->serializer->serialize($definition, ['first' => ['a'], 'second' => ['b', 123]]);
            static::fail('Expected a FieldSerializationException');
        } catch (FieldSerializationException $e) {
            static::assertSame('groups.second[1]', $e->path);
            static::assertSame('Field "groups.second[1]" in item "test_entity" could not be serialized', $e->getMessage());
        }
    }

    public function testAFailureDeserializingAMapOfListsIsPathedToTheElement(): void
    {
        $definition = $this->createMapOfListsDefinition();

        $attribute = AttributeValue::create(['M' => [
            'first' => ['L' => [['S' => 'a']]],
            'second' => ['L' => [['S' => 'b'], ['N' => '7']]],
        ]]);

        try {
            $this->serializer->deserialize($definition, $attribute);
            static::fail('Expected a FieldDeserializationException');
        } catch (FieldDeserializationException $e) {
            static::assertSame('groups.second[1]', $e->path);
        }
    }

    public function testSerializeMapOfLists(): void
    {
        $stringSerializer = new StringFieldSerializer();
        $listValueDef = new FieldDefinition('groups.value', 'array', true, false, null, new ListFieldSerializer(), new FieldDefinition('groups.value.value', 'string', false, false, null, $stringSerializer));
        $definition = new FieldDefinition('groups', 'array', false, false, null, new MapFieldSerializer(), $listValueDef);

        $value = ['first' => ['a', 'b'], 'second' => ['c']];
        $attribute = $this->serializer->serialize($definition, $value);

        static::assertEquals(
            AttributeValue::create([
                'M' => [
                    'first' => ['L' => [['S' => 'a'], ['S' => 'b']]],
                    'second' => ['L' => [['S' => 'c']]],
                ],
            ]),
            $attribute,
        );
    }

    /**
     * Map of lists: array<string, list<string>>
     */
    public function testDeserializeMapOfLists(): void
    {
        $stringSerializer = new StringFieldSerializer();
        $listValueDef = new FieldDefinition('groups.value', 'array', true, false, null, new ListFieldSerializer(), new FieldDefinition('groups.value.value', 'string', false, false, null, $stringSerializer));
        $definition = new FieldDefinition('groups', 'array', false, false, null, new MapFieldSerializer(), $listValueDef);

        $attribute = AttributeValue::create([
            'M' => [
                'first' => ['L' => [['S' => 'a'], ['S' => 'b']]],
                'second' => ['L' => [['S' => 'c']]],
            ],
        ]);

        $result = $this->serializer->deserialize($definition, $attribute);

        static::assertSame(['first' => ['a', 'b'], 'second' => ['c']], $result);
    }

    /**
     * Nested map: array<string, array<string, string>>
     */
    public function testSerializeNestedMap(): void
    {
        $stringSerializer = new StringFieldSerializer();
        $innerMapDef = new FieldDefinition('meta.value', 'array', true, false, null, new MapFieldSerializer(), new FieldDefinition('meta.value.value', 'string', false, false, null, $stringSerializer));
        $definition = new FieldDefinition('meta', 'array', false, false, null, new MapFieldSerializer(), $innerMapDef);

        $value = ['outer' => ['inner' => 'v'], 'other' => ['k' => 'x']];
        $attribute = $this->serializer->serialize($definition, $value);

        static::assertEquals(
            AttributeValue::create([
                'M' => [
                    'outer' => ['M' => ['inner' => ['S' => 'v']]],
                    'other' => ['M' => ['k' => ['S' => 'x']]],
                ],
            ]),
            $attribute,
        );
    }

    /**
     * Nested map: array<string, array<string, string>>
     */
    public function testDeserializeNestedMap(): void
    {
        $stringSerializer = new StringFieldSerializer();
        $innerMapDef = new FieldDefinition('meta.value', 'array', true, false, null, new MapFieldSerializer(), new FieldDefinition('meta.value.value', 'string', false, false, null, $stringSerializer));
        $definition = new FieldDefinition('meta', 'array', false, false, null, new MapFieldSerializer(), $innerMapDef);

        $attribute = AttributeValue::create([
            'M' => [
                'outer' => ['M' => ['inner' => ['S' => 'v']]],
                'other' => ['M' => ['k' => ['S' => 'x']]],
            ],
        ]);

        $result = $this->serializer->deserialize($definition, $attribute);

        static::assertSame(['outer' => ['inner' => 'v'], 'other' => ['k' => 'x']], $result);
    }

    /**
     * `groups: array<string, list<string>>`, named the way the definition builder names a nested value
     * definition — after the property, at every level.
     */
    private function createMapOfListsDefinition(): FieldDefinition
    {
        $leaf = new FieldDefinition('groups.value', 'string', false, false, null, new StringFieldSerializer());
        $listValueDef = new FieldDefinition('groups.value', 'array', true, false, null, new ListFieldSerializer(), $leaf);

        $definition = new FieldDefinition('groups', 'array', false, false, null, new MapFieldSerializer(), $listValueDef);
        new EntityDefinition('test_entity', 'test_entity', CustomerEntity::class, null, ['groups' => $definition], new KeySchema('groups'));

        return $definition;
    }

    private function createMapDefinition(?AbstractFieldSerializer $valueSerializer = null): FieldDefinition
    {
        $valueDef = $valueSerializer === null
            ? null
            : new FieldDefinition('meta.value', 'string', false, false, null, $valueSerializer);

        $definition = new FieldDefinition('meta', 'array', false, false, null, new MapFieldSerializer(), $valueDef);
        $entityDefinition = new EntityDefinition('test_entity', 'test_entity', CustomerEntity::class, null, [], new KeySchema('id'));
        $definition->setEntityDefinition($entityDefinition);

        return $definition;
    }
}
