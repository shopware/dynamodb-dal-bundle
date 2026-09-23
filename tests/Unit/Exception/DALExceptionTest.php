<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Exception;

use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\FieldDefinition;
use Shopware\DynamodbDalBundle\Definition\KeySchema;
use Shopware\DynamodbDalBundle\Exception\DALException;
use Shopware\DynamodbDalBundle\Exception\FieldDeserializationException;
use Shopware\DynamodbDalBundle\Exception\FieldMissingDeserializedValueException;
use Shopware\DynamodbDalBundle\Exception\FieldMissingSerializedValueException;
use Shopware\DynamodbDalBundle\Exception\FieldSerializationException;
use Shopware\DynamodbDalBundle\Exception\MissingAttributeValueException;
use Shopware\DynamodbDalBundle\Exception\NullFilterValueException;
use Shopware\DynamodbDalBundle\Exception\UnknownEntityDefinitionException;
use Shopware\DynamodbDalBundle\Exception\UnknownFieldException;
use Shopware\DynamodbDalBundle\Exception\WrongTypeException;
use Shopware\DynamodbDalBundle\Serializer\Field\StringFieldSerializer;
use Shopware\DynamodbDalBundle\Tests\Unit\Fixtures\CustomerEntity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Each failure is its own class and says in its message what happened and where. The definition it
 * happened on stays on the exception, for a caller that wants more than the sentence.
 */
#[CoversClass(FieldDeserializationException::class)]
#[CoversClass(FieldMissingDeserializedValueException::class)]
#[CoversClass(FieldMissingSerializedValueException::class)]
#[CoversClass(FieldSerializationException::class)]
#[CoversClass(MissingAttributeValueException::class)]
#[CoversClass(NullFilterValueException::class)]
#[CoversClass(UnknownEntityDefinitionException::class)]
#[CoversClass(UnknownFieldException::class)]
#[CoversClass(WrongTypeException::class)]
class DALExceptionTest extends TestCase
{
    /**
     * Whatever the DAL was doing at the time, one catch reaches it.
     */
    public function testEveryFailureIsADalException(): void
    {
        static::assertInstanceOf(DALException::class, new UnknownEntityDefinitionException('order'));
        static::assertInstanceOf(DALException::class, new UnknownFieldException($this->entityDefinition(), 'nope'));
        static::assertInstanceOf(DALException::class, new WrongTypeException($this->fieldDefinition(), 'string', 1));
        static::assertInstanceOf(\RuntimeException::class, new NullFilterValueException($this->fieldDefinition()));
    }

    public function testUnknownEntityDefinition(): void
    {
        $exception = new UnknownEntityDefinitionException('order');

        static::assertSame('No entity definition is registered for "order"', $exception->getMessage());
        static::assertSame('order', $exception->identifier);
    }

    public function testUnknownField(): void
    {
        $entityDefinition = $this->entityDefinition();
        $exception = new UnknownFieldException($entityDefinition, 'doesNotExist');

        static::assertSame('Unknown field "doesNotExist" in item "customer"', $exception->getMessage());
        static::assertSame($entityDefinition, $exception->entityDefinition);
        static::assertSame('doesNotExist', $exception->field);
    }

    /**
     * One failure for a value missing on the way into DynamoDB, whether it was never handed in or
     * serialized to nothing: what the caller has to fix is the same either way.
     */
    public function testFieldMissingSerializedValue(): void
    {
        $fieldDefinition = $this->fieldDefinition();
        $exception = new FieldMissingSerializedValueException($fieldDefinition);

        static::assertSame(
            'Missing required value for field "label" in item "customer" during serialization',
            $exception->getMessage(),
        );
        static::assertSame($fieldDefinition, $exception->fieldDefinition);
    }

    public function testFieldMissingDeserializedValue(): void
    {
        $exception = new FieldMissingDeserializedValueException($this->fieldDefinition());

        static::assertStringStartsWith(
            'Missing required value for field "label" in item "customer" after deserialization and denormalization.',
            $exception->getMessage(),
        );
    }

    public function testMissingAttributeValue(): void
    {
        $fieldDefinition = $this->fieldDefinition();
        $exception = new MissingAttributeValueException($fieldDefinition, 'S');

        static::assertSame(
            'Missing expected DynamoDB attribute value of type "S" for field "label" in item "customer"',
            $exception->getMessage(),
        );
        static::assertSame($fieldDefinition, $exception->fieldDefinition);
        static::assertSame('S', $exception->dynamoDbType);
    }

    public function testWrongType(): void
    {
        $exception = new WrongTypeException($this->fieldDefinition(), 'string', 42);

        static::assertSame(
            'Expected type "string" for field "label" in item "customer", got "integer"',
            $exception->getMessage(),
        );
        static::assertSame('integer', $exception->actualType);
    }

    public function testWrongTypeNamesTheClassOfAnObject(): void
    {
        $exception = new WrongTypeException($this->fieldDefinition(), 'string', new \stdClass());

        static::assertStringEndsWith('got "stdClass"', $exception->getMessage());
        static::assertSame(\stdClass::class, $exception->actualType);
    }

    public function testNullFilterValue(): void
    {
        $exception = new NullFilterValueException($this->fieldDefinition());

        static::assertStringStartsWith(
            'Filter value for field "label" in item "customer" must not be null.',
            $exception->getMessage(),
        );
    }

    public function testFieldSerializationKeepsTheCause(): void
    {
        $cause = new \RuntimeException('the real problem');
        $exception = new FieldSerializationException($this->fieldDefinition(), $cause);

        static::assertSame('Field "label" in item "customer" could not be serialized', $exception->getMessage());
        static::assertSame($cause, $exception->getPrevious());
        static::assertSame('label', $exception->path);
    }

    public function testFieldDeserializationKeepsTheCause(): void
    {
        $cause = new \RuntimeException('the real problem');
        $exception = new FieldDeserializationException($this->fieldDefinition(), $cause);

        static::assertSame('Field "label" in item "customer" could not be deserialized', $exception->getMessage());
        static::assertSame($cause, $exception->getPrevious());
        static::assertSame('label', $exception->path);
    }

    /**
     * The path is where in the item it happened, and every level that catches one prepends its own
     * segment, so the message points at the element inside the collection rather than at the field.
     */
    public function testThePathIsNamedInTheMessage(): void
    {
        $serialization = new FieldSerializationException($this->fieldDefinition(), null, 'label.value');
        $deserialization = new FieldDeserializationException($this->fieldDefinition(), null, 'label.value');

        static::assertSame(
            'Field "label.value" in item "customer" could not be serialized',
            $serialization->getMessage(),
        );
        static::assertSame('label.value', $serialization->path);

        static::assertSame(
            'Field "label.value" in item "customer" could not be deserialized',
            $deserialization->getMessage(),
        );
        static::assertSame('label.value', $deserialization->path);
    }

    /**
     * A caller deeper in names the element it failed on, not the field it sits under, so the path is
     * put under the field rather than replacing it.
     */
    public function testAPathThatDoesNotNameTheFieldIsPutUnderIt(): void
    {
        $exception = new FieldSerializationException($this->fieldDefinition(), null, 'inner');

        static::assertSame('label.inner', $exception->path);
        static::assertSame('Field "label.inner" in item "customer" could not be serialized', $exception->getMessage());
    }

    /**
     * A collection serializer names the element it was on, opening on the separator DynamoDB addresses
     * it by, and the field it hangs off is put in front of it without a second one.
     */
    public function testASegmentHangsOffTheFieldOnItsOwnSeparator(): void
    {
        $index = new FieldSerializationException($this->fieldDefinition(), null, '[1][0]');
        $key = new FieldDeserializationException($this->fieldDefinition(), null, '.colour');

        static::assertSame('label[1][0]', $index->path);
        static::assertSame('label.colour', $key->path);
    }

    /**
     * A field that is not inside a collection is its own path, so a caller with nothing to add passes
     * nothing and the field still names itself.
     */
    public function testAMissingPathFallsBackToTheFieldName(): void
    {
        $none = new FieldSerializationException($this->fieldDefinition());
        $empty = new FieldSerializationException($this->fieldDefinition(), null, '');

        static::assertSame('label', $none->path);
        static::assertSame('label', $empty->path);
        static::assertSame('Field "label" in item "customer" could not be serialized', $empty->getMessage());
    }

    /**
     * @return FieldDefinition<CustomerEntity>
     */
    private function fieldDefinition(): FieldDefinition
    {
        /** @var FieldDefinition<CustomerEntity> $fieldDefinition */
        $fieldDefinition = new FieldDefinition('label', 'string', true, true, null, new StringFieldSerializer());

        // Constructing it is what gives the field definition its back-reference.
        new EntityDefinition(
            'customer',
            'customer-table',
            CustomerEntity::class,
            null,
            ['label' => $fieldDefinition],
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
