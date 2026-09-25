<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Serializer\Field;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Definition\FieldDefinition;
use Shopware\DynamodbDalBundle\Exception\FieldDeserializationException;
use Shopware\DynamodbDalBundle\Exception\FieldSerializationException;
use Shopware\DynamodbDalBundle\Exception\MissingAttributeValueException;
use Shopware\DynamodbDalBundle\Exception\WrongTypeException;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;

/**
 * Turns the value of an entity property into a DynamoDB attribute and back. A registered subclass is picked up
 * without a tag, and a property takes the first serializer whose {@see supports()} claims its type, by tag priority:
 * the application's own at the default 0, the bundle's at -100, and the JSON one last at -500.
 *
 * @template ValueType = mixed
 * @template TargetType of string = string
 */
abstract class AbstractFieldSerializer
{
    /**
     * Called during container build time when compiling item definitions.
     * May throw a \LogicException for a supported type in a shape it cannot serialize, such as an enum without cases.
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
     *
     * @throws WrongTypeException
     */
    abstract public function serialize(FieldDefinition $definition, mixed $value): AttributeValue;

    /**
     * Convert a value **from** its DynamoDB target AttributeValue.
     *
     * @param FieldDefinition<AbstractEntity, TargetType> $definition
     *
     * @throws MissingAttributeValueException
     *
     * @return ValueType
     */
    abstract public function deserialize(FieldDefinition $definition, AttributeValue $attributeValue): mixed;

    /**
     * Where inside a collection a failure happened, as a document path DynamoDB addresses an element
     * by: this element's own segment (`[2]`, `.colour`), plus whatever the failure from inside it
     * named below that.
     *
     * @param string $segment This element's segment, opening on its separator
     * @param FieldDefinition<AbstractEntity> $valueDefinition The definition the failure came out of
     */
    protected static function elementPath(string $segment, FieldDefinition $valueDefinition, \Throwable $previous): string
    {
        $inner = match (true) {
            $previous instanceof FieldSerializationException,
            $previous instanceof FieldDeserializationException => $previous->path,
            default => null,
        };

        // Anything else named nothing below this element, or named it against another definition.
        if ($inner === null || !str_starts_with($inner, $valueDefinition->getName())) {
            return $segment;
        }

        return $segment . substr($inner, \strlen($valueDefinition->getName()));
    }
}
