<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Exception;

use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\FieldDefinition;
use Shopware\DynamodbDalBundle\Serializer\Field\AbstractFieldSerializer;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;

class SerializerException extends DALException
{
    public const string FIELD_ATTRIBUTE_VALUE_MISSING = 'DAL__SERIALIZER__FIELD_ATTRIBUTE_VALUE_MISSING';
    public const string FIELD_NOT_DESERIALIZABLE = 'DAL__SERIALIZER__FIELD_NOT_DESERIALIZABLE';
    public const string FIELD_NOT_SERIALIZABLE = 'DAL__SERIALIZER__FIELD_NOT_SERIALIZABLE';
    public const string FIELD_VALUE_MISSING_AFTER_SERIALIZATION = 'DAL__SERIALIZER__FIELD_VALUE_MISSING_AFTER_SERIALIZATION';
    public const string MISSING_REQUIRED_FIELD_VALUE = 'DAL__SERIALIZER__MISSING_REQUIRED_FIELD_VALUE';
    public const string UNKNOWN_FIELD_TO_SERIALIZE = 'DAL__SERIALIZER__UNKNOWN_FIELD_TO_SERIALIZE';
    public const string WRONG_TYPE = 'DAL__SERIALIZER__WRONG_TYPE';

    /**
     * @param class-string<AbstractFieldSerializer>|null $fieldSerializer
     * @param array<string, mixed> $parameters
     */
    public function __construct(
        string $message,
        string $code,
        protected ?string $fieldSerializer = null,
        array $parameters = [],
        protected ?FieldDefinition $fieldDefinition = null,
        protected ?EntityDefinition $entityDefinition = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $message,
            $code,
            $parameters,
            $fieldDefinition,
            $entityDefinition,
            $previous,
        );
    }

    /**
     * @param class-string<AbstractFieldSerializer> $fieldSerializer
     */
    public static function fieldAttributeValueMissing(
        string $fieldSerializer,
        AttributeValue $attributeValue,
        FieldDefinition $fieldDefinition,
        string $dynamoDBType,
        ?\Throwable $previous = null,
    ): self {
        return new self(
            message: 'Missing expected DynamoDB attribute value of type "{dynamoDBType}" for field "{field}" in item "{entity}"',
            code: self::FIELD_ATTRIBUTE_VALUE_MISSING,
            fieldSerializer: $fieldSerializer,
            fieldDefinition: $fieldDefinition,
            parameters: [
                'dynamoDBType' => $dynamoDBType,
                'attributeValue' => $attributeValue->requestBody(),
            ],
            previous: $previous,
        );
    }

    /**
     * @param class-string<AbstractFieldSerializer> $fieldSerializer
     */
    public static function fieldDeserializationFailed(
        string $fieldSerializer,
        FieldDefinition $fieldDefinition,
        AttributeValue $attributeValue,
        ?\Throwable $previous = null,
        ?string $nestedPath = null,
    ): self {
        $message = 'Field "{field}" in item "{entity}" could not be deserialized';
        $parameters = ['attributeValue' => $attributeValue->requestBody()];
        if ($nestedPath !== null && $nestedPath !== '') {
            $message .= ' (nested path: {nestedPath})';
            $parameters['nestedPath'] = $nestedPath;
        }

        return new self(
            message: $message,
            code: self::FIELD_NOT_DESERIALIZABLE,
            fieldSerializer: $fieldSerializer,
            fieldDefinition: $fieldDefinition,
            parameters: $parameters,
            previous: $previous,
        );
    }

    /**
     * @param class-string<AbstractFieldSerializer> $fieldSerializer
     */
    public static function fieldSerializationFailed(
        string $fieldSerializer,
        FieldDefinition $fieldDefinition,
        mixed $value,
        ?\Throwable $previous = null,
        ?string $nestedPath = null,
    ): self {
        $message = 'Field "{field}" in item "{entity}" could not be serialized';
        $parameters = ['value' => $value];
        if ($nestedPath !== null && $nestedPath !== '') {
            $message .= ' (nested path: {nestedPath})';
            $parameters['nestedPath'] = $nestedPath;
        }

        return new self(
            message: $message,
            code: self::FIELD_NOT_SERIALIZABLE,
            fieldSerializer: $fieldSerializer,
            fieldDefinition: $fieldDefinition,
            parameters: $parameters,
            previous: $previous,
        );
    }

    public static function requiredSerializationFieldValueMissing(FieldDefinition $fieldDefinition): self
    {
        return new self(
            message: 'Missing required value for field "{field}" in item "{entity}" before serialization',
            code: self::MISSING_REQUIRED_FIELD_VALUE,
            fieldDefinition: $fieldDefinition,
        );
    }

    /**
     * A field expected in the serialized output is absent — e.g. a nullable field serialized to nothing
     * where a value was required (a primary/index key attribute must always be present).
     */
    public static function fieldValueMissingAfterSerialization(FieldDefinition $fieldDefinition): self
    {
        return new self(
            message: 'Field "{field}" in item "{entity}" produced no value after serialization, but a value is required',
            code: self::FIELD_VALUE_MISSING_AFTER_SERIALIZATION,
            fieldDefinition: $fieldDefinition,
        );
    }

    public static function requiredDeserializationFieldValueMissing(FieldDefinition $fieldDefinition): self
    {
        return new self(
            message: 'Missing required value for field "{field}" in item "{entity}" after deserialization and denormalization. Consider providing a default value, allowing null or migrate in the denormalization step.',
            code: self::MISSING_REQUIRED_FIELD_VALUE,
            fieldDefinition: $fieldDefinition,
        );
    }

    public static function unknownFieldToSerialize(EntityDefinition $entityDefinition, string $name): self
    {
        return new self(
            message: 'Unknown field "{field}" in item "{entity}" cannot be serialized',
            code: self::UNKNOWN_FIELD_TO_SERIALIZE,
            entityDefinition: $entityDefinition,
            parameters: [
                'field' => $name,
            ],
        );
    }

    /**
     * @param class-string<AbstractFieldSerializer> $fieldSerializer
     */
    public static function wrongType(
        string $fieldSerializer,
        FieldDefinition $fieldDefinition,
        string $expectedType,
        mixed $actualValue,
        ?\Throwable $previous = null,
    ): self {
        $actualType = \is_object($actualValue) ? $actualValue::class : \gettype($actualValue);

        return new self(
            message: 'Expected type "{expectedType}" for field "{field}" in item "{entity}", got "{actualType}"',
            code: self::WRONG_TYPE,
            fieldSerializer: $fieldSerializer,
            fieldDefinition: $fieldDefinition,
            parameters: [
                'expectedType' => $expectedType,
                'actualType' => $actualType,
            ],
            previous: $previous,
        );
    }
}
