<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Exception;

use Shopware\DynamodbDalBundle\Tests\Unit\Fixtures\CustomerEntity;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\FieldDefinition;
use Shopware\DynamodbDalBundle\Definition\KeySchema;
use Shopware\DynamodbDalBundle\Exception\DALException;
use Shopware\DynamodbDalBundle\Exception\SerializerException;
use Shopware\DynamodbDalBundle\Serializer\Field\AbstractFieldSerializer;
use Shopware\DynamodbDalBundle\Serializer\Field\StringFieldSerializer;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DALException::class)]
#[CoversClass(SerializerException::class)]
class SerializerExceptionTest extends TestCase
{
    public function testFieldAttributeValueMissing(): void
    {
        $attributeValue = new AttributeValue(['S' => 'value']);
        $fieldDefinition = $this->fieldDefinition();

        $exception = SerializerException::fieldAttributeValueMissing(
            AbstractFieldSerializer::class,
            $attributeValue,
            $fieldDefinition,
            'S'
        );

        static::assertSame(SerializerException::FIELD_ATTRIBUTE_VALUE_MISSING, $exception->errorCode);
        static::assertStringContainsString('Missing expected DynamoDB attribute value', $exception->getMessage());
        static::assertStringContainsString('for field "label" in item "customer"', $exception->getMessage());
        static::assertSame([
            'entity' => 'customer',
            'field' => 'label',
            'dynamoDBType' => 'S',
            'attributeValue' => $attributeValue->requestBody(),
        ], $exception->getParameters());
    }

    public function testFieldDeserializationFailed(): void
    {
        $fieldDefinition = $this->fieldDefinition();

        $exception = SerializerException::fieldDeserializationFailed(
            AbstractFieldSerializer::class,
            $fieldDefinition,
            AttributeValue::create(['S' => 'value']),
        );

        static::assertSame(SerializerException::FIELD_NOT_DESERIALIZABLE, $exception->errorCode);
        static::assertStringContainsString('could not be deserialized', $exception->getMessage());
        static::assertStringContainsString('Field "label" in item "customer"', $exception->getMessage());
        static::assertSame([
            'entity' => 'customer',
            'field' => 'label',
            'attributeValue' => ['S' => 'value'],
        ], $exception->getParameters());
    }

    public function testRequiredSerializationFieldValueMissing(): void
    {
        $fieldDefinition = $this->fieldDefinition();

        $exception = SerializerException::requiredSerializationFieldValueMissing($fieldDefinition);

        static::assertSame(SerializerException::MISSING_REQUIRED_FIELD_VALUE, $exception->errorCode);
        static::assertStringContainsString('Missing required value for field', $exception->getMessage());
        static::assertStringContainsString('field "label" in item "customer" before serialization', $exception->getMessage());
        static::assertSame(['entity' => 'customer', 'field' => 'label'], $exception->getParameters());
    }

    public function testRequiredDeserializationFieldValueMissing(): void
    {
        $fieldDefinition = $this->fieldDefinition();

        $exception = SerializerException::requiredDeserializationFieldValueMissing($fieldDefinition);

        static::assertSame(SerializerException::MISSING_REQUIRED_FIELD_VALUE, $exception->errorCode);
        static::assertStringContainsString('Missing required value for field', $exception->getMessage());
        static::assertStringContainsString('field "label" in item "customer" after deserialization', $exception->getMessage());
        static::assertSame(['entity' => 'customer', 'field' => 'label'], $exception->getParameters());
    }

    public function testUnknownFieldToSerialize(): void
    {
        $entityDefinition = $this->entityDefinition();

        $exception = SerializerException::unknownFieldToSerialize($entityDefinition, 'unknown_field');

        static::assertSame(SerializerException::UNKNOWN_FIELD_TO_SERIALIZE, $exception->errorCode);
        static::assertStringContainsString('Unknown field', $exception->getMessage());
        static::assertStringContainsString('Unknown field "unknown_field" in item "customer"', $exception->getMessage());
        static::assertSame(['entity' => 'customer', 'field' => 'unknown_field'], $exception->getParameters());
    }

    public function testGettersReturnDefinitions(): void
    {
        $attributeValue = new AttributeValue(['S' => 'value']);

        $fieldDefinition = $this->fieldDefinition();
        $entityDefinition = $fieldDefinition->getEntityDefinition();

        $exception = SerializerException::fieldAttributeValueMissing(
            AbstractFieldSerializer::class,
            $attributeValue,
            $fieldDefinition,
            'S',
        );

        static::assertSame($fieldDefinition, $exception->getFieldDefinition());
        static::assertSame($entityDefinition, $exception->getEntityDefinition());
    }

    public function testWrongType(): void
    {
        $fieldDefinition = $this->fieldDefinition('meta');

        $exception = SerializerException::wrongType(
            AbstractFieldSerializer::class,
            $fieldDefinition,
            'array',
            'not-an-array',
        );

        static::assertSame(SerializerException::WRONG_TYPE, $exception->errorCode);
        static::assertStringContainsString('Expected type "array"', $exception->getMessage());
        static::assertStringContainsString('got "string"', $exception->getMessage());
        $params = $exception->getParameters();
        static::assertSame('array', $params['expectedType']);
        static::assertSame('string', $params['actualType']);
        static::assertSame('meta', $params['field']);
        static::assertSame('customer', $params['entity']);
    }

    public function testWrongTypeWithObjectActualValue(): void
    {
        $fieldDefinition = $this->fieldDefinition();

        $exception = SerializerException::wrongType(
            AbstractFieldSerializer::class,
            $fieldDefinition,
            'string',
            new \stdClass(),
        );

        static::assertStringContainsString('got "stdClass"', $exception->getMessage());
        static::assertSame('stdClass', $exception->getParameters()['actualType']);
    }

    public function testFieldSerializationFailed(): void
    {
        $fieldDefinition = $this->fieldDefinition();

        $exception = SerializerException::fieldSerializationFailed(
            AbstractFieldSerializer::class,
            $fieldDefinition,
            'value',
            new \RuntimeException('inner'),
        );

        static::assertSame(SerializerException::FIELD_NOT_SERIALIZABLE, $exception->errorCode);
        static::assertStringContainsString('could not be serialized', $exception->getMessage());
        static::assertSame([
            'entity' => 'customer',
            'field' => 'label',
            'value' => 'value',
        ], $exception->getParameters());
    }

    public function testFieldDeserializationFailedWithNestedPath(): void
    {
        $fieldDefinition = $this->fieldDefinition();

        $exception = SerializerException::fieldDeserializationFailed(
            AbstractFieldSerializer::class,
            $fieldDefinition,
            AttributeValue::create(['S' => 'value']),
            null,
            'matrix.value',
        );

        static::assertStringContainsString('nested path: matrix.value', $exception->getMessage());
        static::assertSame([
            'entity' => 'customer',
            'field' => 'label',
            'attributeValue' => ['S' => 'value'],
            'nestedPath' => 'matrix.value',
        ], $exception->getParameters());
    }

    public function testFieldSerializationFailedWithNestedPath(): void
    {
        $fieldDefinition = $this->fieldDefinition();

        $exception = SerializerException::fieldSerializationFailed(
            AbstractFieldSerializer::class,
            $fieldDefinition,
            'value',
            null,
            'groups.value',
        );

        static::assertStringContainsString('could not be serialized', $exception->getMessage());
        static::assertStringContainsString('nested path: groups.value', $exception->getMessage());
        static::assertSame([
            'entity' => 'customer',
            'field' => 'label',
            'value' => 'value',
            'nestedPath' => 'groups.value',
        ], $exception->getParameters());
    }

    /**
     * @return FieldDefinition<CustomerEntity>
     */
    private function fieldDefinition(string $name = 'label'): FieldDefinition
    {
        /** @var FieldDefinition<CustomerEntity> $fieldDefinition */
        $fieldDefinition = new FieldDefinition(
            $name,
            'string',
            true,
            true,
            null,
            new StringFieldSerializer(),
        );

        // Constructing it is what gives the field definition its back-reference.
        new EntityDefinition(
            'customer',
            'customer',
            CustomerEntity::class,
            null,
            [$name => $fieldDefinition],
            new KeySchema('tenantId', 'label'),
        );

        return $fieldDefinition;
    }

    /**
     * @return EntityDefinition<CustomerEntity>
     */
    private function entityDefinition(): EntityDefinition
    {
        return $this->fieldDefinition()->getEntityDefinition();
    }
}
