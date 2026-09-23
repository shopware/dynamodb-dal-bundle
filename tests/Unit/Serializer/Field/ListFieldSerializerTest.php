<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Field;

use Shopware\DynamodbDalBundle\Tests\Unit\Fixtures\CustomerEntity;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\FieldDefinition;
use Shopware\DynamodbDalBundle\Definition\KeySchema;
use Shopware\DynamodbDalBundle\Exception\SerializerException;
use Shopware\DynamodbDalBundle\Serializer\Field\ListFieldSerializer;
use Shopware\DynamodbDalBundle\Serializer\Field\MapFieldSerializer;
use Shopware\DynamodbDalBundle\Serializer\Field\StringFieldSerializer;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ListFieldSerializer::class)]
class ListFieldSerializerTest extends TestCase
{
    private const string ENTITY_NAME = 'test_entity';

    private ListFieldSerializer $serializer;

    protected function setUp(): void
    {
        $this->serializer = new ListFieldSerializer();
    }

    public function testSupports(): void
    {
        static::assertTrue(ListFieldSerializer::supports('array', 'list<string>'));
        static::assertTrue(ListFieldSerializer::supports('array', 'list<int>'));
        static::assertFalse(ListFieldSerializer::supports('array', null));
        static::assertFalse(ListFieldSerializer::supports('array', 'array<string, string>'));
        static::assertFalse(ListFieldSerializer::supports('string', 'list<string>'));
    }

    public function testSerializeUsesValueSerializer(): void
    {
        $definition = $this->createStringListDefinition();

        $value = ['a', 'b', 'c'];
        $attribute = $this->serializer->serialize($definition, $value);

        static::assertEquals(
            AttributeValue::create([
                'L' => [
                    ['S' => 'a'],
                    ['S' => 'b'],
                    ['S' => 'c'],
                ],
            ]),
            $attribute,
        );
    }

    public function testSerializeStoresNullAsDynamoNull(): void
    {
        $definition = $this->createStringListDefinition();

        $value = ['x', null, 'z'];
        $attribute = $this->serializer->serialize($definition, $value);

        static::assertEquals(
            AttributeValue::create([
                'L' => [
                    ['S' => 'x'],
                    ['NULL' => true],
                    ['S' => 'z'],
                ],
            ]),
            $attribute,
        );
    }

    public function testSerializeEmptyArray(): void
    {
        $definition = $this->createStringListDefinition();

        $attribute = $this->serializer->serialize($definition, []);

        static::assertEquals(AttributeValue::create(['L' => []]), $attribute);
    }

    public function testSerializeThrowsWhenValueTypeMissing(): void
    {
        $definition = $this->createListDefinitionWithoutValueType();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('List field "items" must have value type');

        $this->serializer->serialize($definition, ['a']);
    }

    public function testSerializeThrowsWhenValueIsNotArray(): void
    {
        $definition = $this->createStringListDefinition();

        $this->expectException(SerializerException::class);
        $this->expectExceptionMessage('Expected type "array" for field "items" in item "' . self::ENTITY_NAME . '", got "string"');

        /** @phpstan-ignore-next-line argument.type (intentional wrong type to trigger exception) */
        $this->serializer->serialize($definition, 'not-an-array');
    }

    public function testDeserializeThrowsWhenValueTypeMissing(): void
    {
        $definition = $this->createListDefinitionWithoutValueType();

        $attribute = AttributeValue::create(['L' => [['S' => 'a']]]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('List field "items" must have value type');

        $this->serializer->deserialize($definition, $attribute);
    }

    public function testDeserializeUsesValueSerializer(): void
    {
        $definition = $this->createStringListDefinition();

        $attribute = AttributeValue::create([
            'L' => [
                ['S' => 'x'],
                ['S' => 'y'],
            ],
        ]);

        $result = $this->serializer->deserialize($definition, $attribute);

        static::assertIsArray($result);
        static::assertSame(['x', 'y'], $result);
    }

    public function testDeserializeMapsDynamoNullToPhpNull(): void
    {
        $definition = $this->createStringListDefinition();

        $attribute = AttributeValue::create([
            'L' => [
                ['S' => 'a'],
                ['NULL' => true],
            ],
        ]);

        $result = $this->serializer->deserialize($definition, $attribute);

        static::assertSame(['a', null], $result);
    }

    public function testDeserializeEmptyList(): void
    {
        $definition = $this->createStringListDefinition();

        $attribute = AttributeValue::create(['L' => []]);

        $result = $this->serializer->deserialize($definition, $attribute);

        static::assertSame([], $result);
    }

    public function testDeserializeThrowsWhenListMissing(): void
    {
        $definition = $this->createStringListDefinition();

        $attribute = AttributeValue::create(['S' => 'not-a-list']);

        $this->expectException(SerializerException::class);

        $this->serializer->deserialize($definition, $attribute);
    }

    /**
     * Nested list: list<list<string>>
     */
    public function testSerializeNestedListOfLists(): void
    {
        $definition = $this->createNestedListDefinition();

        $value = [['a', 'b'], ['c'], []];
        $attribute = $this->serializer->serialize($definition, $value);

        static::assertEquals(
            AttributeValue::create([
                'L' => [
                    ['L' => [['S' => 'a'], ['S' => 'b']]],
                    ['L' => [['S' => 'c']]],
                    ['L' => []],
                ],
            ]),
            $attribute,
        );
    }

    /**
     * Nested list: list<list<string>>
     */
    public function testDeserializeNestedListOfLists(): void
    {
        $definition = $this->createNestedListDefinition();

        $attribute = AttributeValue::create([
            'L' => [
                ['L' => [['S' => 'a'], ['S' => 'b']]],
                ['L' => [['S' => 'c']]],
            ],
        ]);

        $result = $this->serializer->deserialize($definition, $attribute);

        static::assertSame([['a', 'b'], ['c']], $result);
    }

    /**
     * List of maps: list<array<string, string>>
     */
    public function testSerializeListOfMaps(): void
    {
        $definition = $this->createListOfMapsDefinition();

        $value = [['x' => 'a', 'y' => 'b'], ['z' => 'c']];
        $attribute = $this->serializer->serialize($definition, $value);

        static::assertEquals(
            AttributeValue::create([
                'L' => [
                    ['M' => ['x' => ['S' => 'a'], 'y' => ['S' => 'b']]],
                    ['M' => ['z' => ['S' => 'c']]],
                ],
            ]),
            $attribute,
        );
    }

    /**
     * List of maps: list<array<string, string>>
     */
    public function testDeserializeListOfMaps(): void
    {
        $definition = $this->createListOfMapsDefinition();

        $attribute = AttributeValue::create([
            'L' => [
                ['M' => ['x' => ['S' => 'a'], 'y' => ['S' => 'b']]],
                ['M' => ['z' => ['S' => 'c']]],
            ],
        ]);

        $result = $this->serializer->deserialize($definition, $attribute);

        static::assertSame([['x' => 'a', 'y' => 'b'], ['z' => 'c']], $result);
    }

    /**
     * Nested list serialization failure includes nested path in exception
     */
    public function testSerializeNestedListWrongTypeIncludesNestedPath(): void
    {
        $definition = $this->createNestedListDefinition();

        $value = [['a', 'b'], 123]; // second element should be list<string>, not int

        try {
            $this->serializer->serialize($definition, $value);
            static::fail('Expected SerializerException');
        } catch (SerializerException $e) {
            static::assertSame(SerializerException::FIELD_NOT_SERIALIZABLE, $e->errorCode);
            static::assertStringContainsString('matrix.value', $e->getMessage());
            static::assertStringContainsString(self::ENTITY_NAME, $e->getMessage());
            static::assertArrayHasKey('nestedPath', $e->getParameters());
            static::assertSame('matrix.value', $e->getParameters()['nestedPath']);
        }
    }

    private function createStringListDefinition(): FieldDefinition
    {
        $valueDef = new FieldDefinition('items.value', 'string', false, false, null, new StringFieldSerializer());

        return $this->attachEntityDefinition(new FieldDefinition('items', 'array', false, false, null, new ListFieldSerializer(), $valueDef));
    }

    private function createListDefinitionWithoutValueType(): FieldDefinition
    {
        return $this->attachEntityDefinition(new FieldDefinition('items', 'array', false, false, null, new ListFieldSerializer()));
    }

    private function createNestedListDefinition(): FieldDefinition
    {
        $stringSerializer = new StringFieldSerializer();
        $innerListDef = new FieldDefinition('matrix.value', 'array', true, false, null, new ListFieldSerializer(), new FieldDefinition('matrix.value.value', 'string', false, false, null, $stringSerializer));

        return $this->attachEntityDefinition(new FieldDefinition('matrix', 'array', false, false, null, new ListFieldSerializer(), $innerListDef));
    }

    private function createListOfMapsDefinition(): FieldDefinition
    {
        $stringSerializer = new StringFieldSerializer();
        $mapValueDef = new FieldDefinition('rows.value', 'array', true, false, null, new MapFieldSerializer(), new FieldDefinition('rows.value.value', 'string', false, false, null, $stringSerializer));

        return $this->attachEntityDefinition(new FieldDefinition('rows', 'array', false, false, null, new ListFieldSerializer(), $mapValueDef));
    }

    private function attachEntityDefinition(FieldDefinition $definition): FieldDefinition
    {
        $definition->setEntityDefinition(new EntityDefinition(self::ENTITY_NAME, self::ENTITY_NAME, CustomerEntity::class, null, [], new KeySchema('id')));

        return $definition;
    }
}
