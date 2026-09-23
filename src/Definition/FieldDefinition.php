<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Definition;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Serializer\Field\AbstractFieldSerializer;

/**
 * @template-covariant Entity of AbstractEntity = AbstractEntity
 *
 * @template Type of string = string
 */
class FieldDefinition
{
    /**
     * @var EntityDefinition<Entity>
     */
    private EntityDefinition $entityDefinition;

    /**
     * @param Type $type
     * @param AbstractFieldSerializer<mixed, Type> $serializer
     */
    public function __construct(
        private readonly string $name,
        private readonly string $type,
        private readonly bool $allowsNull,
        private readonly bool $hasDefaultValue,
        private readonly mixed $defaultValue,
        private readonly AbstractFieldSerializer $serializer,
        private readonly ?FieldDefinition $valueFieldDefinition = null,
    ) {
    }

    /**
     * @return EntityDefinition<Entity>
     */
    public function getEntityDefinition(): EntityDefinition
    {
        return $this->entityDefinition;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return Type - Type name returned from `\ReflectionNamedType::getName()`
     */
    public function getType(): string
    {
        return $this->type;
    }

    public function allowsNull(): bool
    {
        return $this->allowsNull;
    }

    public function hasDefaultValue(): bool
    {
        return $this->hasDefaultValue;
    }

    public function getDefaultValue(): mixed
    {
        return $this->defaultValue;
    }

    public function getExpressionAttributeName(): string
    {
        return "#{$this->name}";
    }

    public function getExpressionValueName(): string
    {
        return ":{$this->name}";
    }

    /**
     * @return AbstractFieldSerializer<mixed, Type>
     */
    public function getSerializer(): AbstractFieldSerializer
    {
        return $this->serializer;
    }

    /**
     * For Map/List fields: definition used to serialize/deserialize each value. Null for scalar fields.
     */
    public function getValueFieldDefinition(): ?FieldDefinition
    {
        return $this->valueFieldDefinition;
    }

    /**
     * @internal - Called by {@see EntityDefinition::__construct()}
     */
    public function setEntityDefinition(EntityDefinition $entityDefinition): void
    {
        if (isset($this->entityDefinition)) {
            throw new \LogicException("Entity definition for field {$this->name} is already set");
        }

        $this->entityDefinition = $entityDefinition;
        $this->valueFieldDefinition?->setEntityDefinition($entityDefinition);
    }
}
