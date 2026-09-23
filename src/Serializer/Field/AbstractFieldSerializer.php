<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Serializer\Field;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Definition\FieldDefinition;
use Shopware\DynamodbDalBundle\Exception\SerializerException;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;

/**
 * @template ValueType = mixed
 * @template TargetType of string = string
 */
abstract class AbstractFieldSerializer
{
    /**
     * Called during container build time when compiling item definitions.
     * May add additional exceptions if a type is supported but not in the correct shape.
     *
     * @param string $type Type name from `\ReflectionNamedType::getName()`
     * @param string|null $docblockType Optional @var type for array fields (e.g. "list<string>", "array<string, int>") to distinguish Map vs List
     *
     * @phpstan-assert-if-true TargetType $type
     */
    abstract public static function supports(string $type, ?string $docblockType = null): bool;

    /**
     * Convert a value **into** its DynamoDB target AttributeValue.
     *
     * @param FieldDefinition<AbstractEntity, TargetType> $definition
     * @param ValueType $value
     */
    abstract public function serialize(FieldDefinition $definition, mixed $value): AttributeValue;

    /**
     * Convert a value **from** its DynamoDB target AttributeValue.
     *
     * @param FieldDefinition<AbstractEntity, TargetType> $definition
     *
     * @throws SerializerException if the provided AttributeValue does not contain the expected value type (e.g. missing "S" for a string field)
     * @throws \Throwable if deserialization fails for any other reason (e.g. invalid value format)
     *
     * @return ValueType
     */
    abstract public function deserialize(FieldDefinition $definition, AttributeValue $attributeValue): mixed;
}
