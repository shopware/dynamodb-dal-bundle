<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle;

use Shopware\DynamodbDalBundle\Attribute\Field;
use Shopware\DynamodbDalBundle\Attribute\Table;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\FieldDefinition;
use Shopware\DynamodbDalBundle\Definition\IndexSchema;
use Shopware\DynamodbDalBundle\Definition\KeySchema;
use Shopware\DynamodbDalBundle\Serializer\AbstractNormalizer;
use Shopware\DynamodbDalBundle\Serializer\Field\AbstractFieldSerializer;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Builds the container definition of a single entity's {@see EntityDefinition} from its `#[Table]`
 * and {@see Field} attributes, validating both as it goes so a mis-declared entity fails the build.
 *
 * @internal
 */
final readonly class DefinitionBuilder
{
    /**
     * @param list<string> $fieldSerializers Service id of every service tagged as an {@see AbstractFieldSerializer}, in the order they are tried
     */
    public function __construct(
        private array $fieldSerializers
    ) {
    }

    /**
     * @param class-string $entityClass
     * @param string $physicalTable The table its items live in, as configured
     *
     * @return array{string, Definition} The entity's item name and the definition to register for it
     */
    public function build(string $entityClass, string $physicalTable): array
    {
        if (!class_exists($entityClass)) {
            throw new \LogicException("Entity {$entityClass} is configured as an entity but does not exist");
        }

        if (!is_subclass_of($entityClass, AbstractEntity::class, true)) {
            throw new \LogicException("Entity {$entityClass} is configured as an entity but does not extend " . AbstractEntity::class);
        }

        $classRef = new \ReflectionClass($entityClass);
        if ($classRef->isAbstract()) {
            throw new \LogicException("Entity {$entityClass} is abstract; configure the entities extending it instead");
        }

        $table = $this->readTable($classRef, $entityClass);

        $this->validateKeyFields($entityClass, $table, $classRef);

        $definition = new Definition(
            EntityDefinition::class,
            [
                '$name' => $table->name,
                '$class' => $entityClass,
                '$table' => $physicalTable,
                '$normalizer' => $table->normalizer !== null ? new Reference($table->normalizer) : null,
                '$fieldDefinitions' => $this->buildFieldDefinitions($classRef, $entityClass),
                '$keySchema' => new Definition(KeySchema::class, [
                    '$hashKey' => $table->hashKey,
                    '$rangeKey' => $table->rangeKey,
                ]),
                '$indexes' => $this->buildIndexes($table),
            ],
        );

        return [$table->name, $definition];
    }

    /**
     * @param \ReflectionClass<AbstractEntity> $classRef
     */
    private function readTable(\ReflectionClass $classRef, string $entityClass): Table
    {
        $tableAttr = $classRef->getAttributes(Table::class)[0] ?? null;
        if (!$tableAttr) {
            throw new \LogicException("Entity {$entityClass} misses the attribute " . Table::class);
        }

        try {
            $table = $tableAttr->newInstance();
        } catch (\ArgumentCountError|\TypeError) {
            throw new \LogicException("Entity {$entityClass} misses a required value of " . Table::class . ' (e.g. $name, $hashKey)');
        }

        if ($table->name === '') {
            throw new \LogicException("Entity {$entityClass} misses the attribute value " . Table::class . '::$name');
        }

        if ($table->normalizer !== null && !is_subclass_of($table->normalizer, AbstractNormalizer::class, true)) {
            throw new \LogicException("Entity {$entityClass} specifies a normalizer which is not an instance of " . AbstractNormalizer::class);
        }

        return $table;
    }

    /**
     * Every declared key field — the table partition and sort key, plus each index's
     * partition and sort key — must be a {@see Field} property of the entity. A cursor
     * or key condition built on a field that does not exist is a programming error that
     * should fail the build, not surface as a malformed `ExclusiveStartKey` at runtime.
     *
     * @param \ReflectionClass<AbstractEntity> $classRef
     */
    private function validateKeyFields(string $entityClass, Table $table, \ReflectionClass $classRef): void
    {
        $fieldTypes = [];
        foreach ($classRef->getProperties() as $property) {
            if ($property->getAttributes(Field::class) !== []) {
                $fieldTypes[$property->getName()] = $property->getType();
            }
        }

        $tableKeyFields = [['table partition key', $table->hashKey]];
        if ($table->rangeKey !== null) {
            $tableKeyFields[] = ['table sort key', $table->rangeKey];
        }

        foreach ($tableKeyFields as [$label, $field]) {
            if ($field === '' || !\array_key_exists($field, $fieldTypes)) {
                throw new \LogicException("Entity {$entityClass} declares {$label} \"{$field}\" which is not a #[Field] property");
            }

            if ($fieldTypes[$field]?->allowsNull() && $table->normalizer === null) {
                throw new \LogicException("Entity {$entityClass} declares {$label} \"{$field}\" which must not be nullable");
            }
        }

        $keyFields = [];
        foreach ($table->indexes as $index) {
            $keyFields[] = ["index '{$index->name}' partition key", $index->keySchema->hashKey];
            if ($index->keySchema->rangeKey !== null) {
                $keyFields[] = ["index '{$index->name}' sort key", $index->keySchema->rangeKey];
            }
        }

        foreach ($keyFields as [$label, $field]) {
            if ($field === '' || !\array_key_exists($field, $fieldTypes)) {
                throw new \LogicException("Entity {$entityClass} declares {$label} \"{$field}\" which is not a #[Field] property");
            }
        }
    }

    /**
     * @return array<string, Definition> - keyed by index name
     */
    private function buildIndexes(Table $table): array
    {
        $indexes = [];
        foreach ($table->indexes as $index) {
            $indexes[$index->name] = new Definition(IndexSchema::class, [
                '$name' => $index->name,
                '$hashKey' => $index->keySchema->hashKey,
                '$rangeKey' => $index->keySchema->rangeKey,
            ]);
        }

        return $indexes;
    }

    /**
     * @param \ReflectionClass<AbstractEntity> $classRef
     *
     * @return array<string, Definition> - keyed by field name, in declaration order
     */
    private function buildFieldDefinitions(\ReflectionClass $classRef, string $entityClass): array
    {
        $fieldDefinitions = [];

        foreach ($classRef->getProperties() as $property) {
            $attributeAttr = current($property->getAttributes(Field::class));
            if (!$attributeAttr) {
                continue;
            }

            /** @var Field $fieldAttr */
            $fieldAttr = $attributeAttr->newInstance();
            $fieldDefinitions[$property->getName()] = $this->buildFieldDefinition($entityClass, $property, $fieldAttr->valueType);
        }

        return $fieldDefinitions;
    }

    private function buildFieldDefinition(string $entityClass, \ReflectionProperty $property, ?string $attributeValueType): Definition
    {
        $type = $this->validatePropertyForField($property);

        $phpType = $type->getName();
        $docblockType = ArrayTypeParser::getDocblockVarType($property);
        $valueType = $this->resolveValueTypeForProperty($property, $phpType, $docblockType, $attributeValueType);

        $fieldSerializer = $this->findFieldSerializer($phpType, $docblockType);
        if ($fieldSerializer === false) {
            throw new \LogicException("Entity property {$entityClass}::\${$property->name} is not supported by any serializer");
        }

        return new Definition(
            FieldDefinition::class,
            [
                '$name' => $property->getName(),
                '$type' => $phpType,
                '$allowsNull' => $type->allowsNull(),
                '$hasDefaultValue' => $property->hasDefaultValue(),
                '$defaultValue' => $property->hasDefaultValue() ? $property->getDefaultValue() : null,
                '$serializer' => new Reference($fieldSerializer),
                '$valueFieldDefinition' => $this->buildValueFieldDefinition($entityClass, $property, $valueType),
            ],
        );
    }

    private function validatePropertyForField(\ReflectionProperty $property): \ReflectionNamedType
    {
        $entityClass = $property->getDeclaringClass()->getName();

        if (!$property->isProtected() && !$property->isPublic()) {
            throw new \LogicException("Entity property {$entityClass}::\${$property->name} has to be protected or public");
        }

        $type = $property->getType();
        if (!$type) {
            throw new \LogicException("Entity property {$entityClass}::\${$property->name} has no type specified");
        }

        if (!$type instanceof \ReflectionNamedType) {
            throw new \LogicException("Entity property {$entityClass}::\${$property->name} specifies a unsupported type " . $type::class);
        }

        return $type;
    }

    private function resolveValueTypeForProperty(
        \ReflectionProperty $property,
        string $phpType,
        ?string $docblockType,
        ?string $attributeValueType,
    ): ?string {
        $entityClass = $property->getDeclaringClass()->getName();
        $propertyName = $property->name;

        if ($phpType !== 'array') {
            return $attributeValueType;
        }

        $isListOrMap = $docblockType !== null && ArrayTypeParser::isListOrMapType($docblockType);
        $valueType = null;
        if ($isListOrMap) {
            $valueType = ArrayTypeParser::extractValueType($docblockType);
            if ($valueType === null) {
                throw new \LogicException("Entity property {$entityClass}::\${$propertyName} @var {$docblockType} could not be parsed for value type. Use a supported type (e.g. list<string>, array<string, int>, or nested list<list<string>>) or set Field::valueType.");
            }
        }

        $valueType ??= $attributeValueType;

        if ($valueType === null && $isListOrMap) {
            throw new \LogicException("Entity property {$entityClass}::\${$propertyName} has array with @var {$docblockType} but value type could not be determined. Set Field::valueType (e.g. valueType: 'string').");
        }

        return $valueType;
    }

    private function buildValueFieldDefinition(string $entityClass, \ReflectionProperty $property, ?string $valueType): ?Definition
    {
        if ($valueType === null) {
            return null;
        }

        // Nested list/map: valueType is e.g. "list<string>" or "array<string,int>" → resolve as PHP type "array" with that docblock
        $isListOrMap = ArrayTypeParser::isListOrMapType($valueType);
        $phpType = $isListOrMap ? 'array' : $valueType;
        $docblockType = $phpType === 'array' ? $valueType : null;
        $valueSerializer = $this->findFieldSerializer($phpType, $docblockType);
        if ($valueSerializer === false) {
            throw new \LogicException("Entity property {$entityClass}::\${$property->name} value type \"{$valueType}\" is not supported by any serializer");
        }

        $innerValueType = $isListOrMap ? ArrayTypeParser::extractValueType($valueType) : null;

        return new Definition(
            FieldDefinition::class,
            [
                '$name' => $property->getName() . '.value',
                '$type' => $phpType,
                '$allowsNull' => true,
                '$hasDefaultValue' => false,
                '$defaultValue' => null,
                '$serializer' => new Reference($valueSerializer),
                '$valueFieldDefinition' => $this->buildValueFieldDefinition($entityClass, $property, $innerValueType),
            ],
        );
    }

    /**
     * @return string|false Service id of the serializer that supports the given type, or false if none
     */
    private function findFieldSerializer(string $phpType, ?string $docblockType): string|false
    {
        return array_find(
            $this->fieldSerializers,
            static fn (string $serviceId): bool => is_subclass_of($serviceId, AbstractFieldSerializer::class, true)
                && $serviceId::supports($phpType, $docblockType),
        ) ?? false;
    }
}
