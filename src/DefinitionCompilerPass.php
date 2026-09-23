<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle;

use Shopware\DynamodbDalBundle\Attribute\Field;
use Shopware\DynamodbDalBundle\Attribute\Table;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\EntityDefinitionRegistry;
use Shopware\DynamodbDalBundle\Definition\FieldDefinition;
use Shopware\DynamodbDalBundle\Definition\IndexSchema;
use Shopware\DynamodbDalBundle\Definition\KeySchema;
use Shopware\DynamodbDalBundle\Serializer\AbstractNormalizer;
use Shopware\DynamodbDalBundle\Serializer\Field\AbstractFieldSerializer;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

class DefinitionCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        // symfony.noFindTaggedServiceIdsCall: intended here, this is build time resolution
        $fieldSerializers = $container->findTaggedServiceIds(AbstractFieldSerializer::class);
        $fieldSerializers = array_filter(
            $fieldSerializers,
            static fn (mixed $tags, string $serviceId): bool => $serviceId !== AbstractFieldSerializer::class,
            \ARRAY_FILTER_USE_BOTH,
        );

        // symfony.noFindTaggedServiceIdsCall: intended here, this is build time resolution
        foreach ($container->findTaggedServiceIds(AbstractEntity::class) as $entityClass => $_) {
            // Left in place, a controller argument typed as an entity would be autowired to an
            // empty instance instead of being resolved from the request.
            $container->removeDefinition($entityClass);

            if ($entityClass === AbstractEntity::class) {
                continue;
            }

            /** @var class-string $entityClass */
            $classRef = new \ReflectionClass($entityClass);
            $itemName = $this->registerEntityDefinition($container, $classRef, $entityClass);
            $context = new EntityCompileContext($container, $itemName, $entityClass, $fieldSerializers);

            foreach ($classRef->getProperties() as $property) {
                $attributeAttr = current($property->getAttributes(Field::class));
                if (!$attributeAttr) {
                    continue;
                }

                /** @var Field $fieldAttr */
                $fieldAttr = $attributeAttr->newInstance();
                $this->registerFieldDefinition($context, $property, $fieldAttr->valueType);
            }
        }

        $container->setDefinition(EntityDefinitionRegistry::class, new Definition(
            EntityDefinitionRegistry::class,
            ['$definitions' => new TaggedIteratorArgument('dal.definition', indexAttribute: 'name', needsIndexes: true)],
        ));
    }

    /**
     * @param \ReflectionClass<object> $classRef
     *
     * @return string The entity definition item name
     */
    private function registerEntityDefinition(ContainerBuilder $container, \ReflectionClass $classRef, string $entityClass): string
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

        $itemName = $table->name;
        if ($itemName === '') {
            throw new \LogicException("Entity {$entityClass} misses the attribute value " . Table::class . '::$name');
        }

        if ($table->normalizer !== null && !is_subclass_of($table->normalizer, AbstractNormalizer::class, true)) {
            throw new \LogicException("Entity {$entityClass} specifies a normalizer which is not an instance of " . AbstractNormalizer::class);
        }

        $this->validateKeyFields($entityClass, $table, $classRef);

        $container
            ->setDefinition("dal.definition.{$itemName}", new Definition(
                EntityDefinition::class,
                [
                    '$name' => $itemName,
                    '$class' => $entityClass,
                    '$table' => \sprintf('%%env(DYNAMODB_TABLE_%s)%%', strtoupper($itemName)),
                    '$normalizer' => $table->normalizer ? new Reference($table->normalizer) : null,
                    '$fieldDefinitions' => new TaggedIteratorArgument("dal.definition.{$itemName}.property", indexAttribute: 'name', needsIndexes: true),
                    '$keySchema' => new Definition(KeySchema::class, [
                        '$hashKey' => $table->hashKey,
                        '$rangeKey' => $table->rangeKey,
                    ]),
                    '$indexes' => $this->createIndexDefinitions($table),
                ],
            ))
            ->setPublic(true)
            ->addTag('dal.definition', ['name' => $itemName]);

        return $itemName;
    }

    /**
     * Every declared key field — the table partition and sort key, plus each index's
     * partition and sort key — must be a {@see Field} property of the entity. A cursor
     * or key condition built on a field that does not exist is a programming error that
     * should fail the build, not surface as a malformed `ExclusiveStartKey` at runtime.
     *
     * @param \ReflectionClass<object> $classRef
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
     * @return array<string, Definition>
     */
    private function createIndexDefinitions(Table $table): array
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

    private function registerFieldDefinition(EntityCompileContext $context, \ReflectionProperty $property, ?string $attributeValueType): void
    {
        $type = $this->validatePropertyForField($property);

        $phpType = $type->getName();
        $docblockType = ArrayTypeParser::getDocblockVarType($property);
        $valueType = $this->resolveValueTypeForProperty($property, $phpType, $docblockType, $attributeValueType);

        $fieldSerializer = $context->findFieldSerializer($phpType, $docblockType);
        if ($fieldSerializer === false) {
            throw new \LogicException("Entity property {$context->entityClass}::\${$property->name} is not supported by any serializer");
        }

        $valueFieldDefinition = $this->createValueFieldDefinition($context, $property, $valueType);

        $context->container->setDefinition(
            "dal.definition.{$context->itemName}.property.{$property->getName()}",
            new Definition(
                FieldDefinition::class,
                [
                    '$name' => $property->getName(),
                    '$type' => $phpType,
                    '$allowsNull' => $type->allowsNull(),
                    '$hasDefaultValue' => $property->hasDefaultValue(),
                    '$defaultValue' => $property->hasDefaultValue() ? $property->getDefaultValue() : null,
                    '$serializer' => new Reference($fieldSerializer),
                    '$valueFieldDefinition' => $valueFieldDefinition,
                ],
            ),
        )
            ->addMethodCall('setEntityDefinition', [new Reference("dal.definition.{$context->itemName}")])
            ->setPublic(false)
            ->addTag("dal.definition.{$context->itemName}.property", ['name' => $property->getName()]);
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

    private function createValueFieldDefinition(EntityCompileContext $context, \ReflectionProperty $property, ?string $valueType): ?Definition
    {
        if ($valueType === null) {
            return null;
        }

        // Nested list/map: valueType is e.g. "list<string>" or "array<string,int>" → resolve as PHP type "array" with that docblock
        $isListOrMap = ArrayTypeParser::isListOrMapType($valueType);
        $phpType = $isListOrMap ? 'array' : $valueType;
        $docblockType = $phpType === 'array' ? $valueType : null;
        $valueSerializer = $context->findFieldSerializer($phpType, $docblockType);
        if ($valueSerializer === false) {
            throw new \LogicException("Entity property {$context->entityClass}::\${$property->name} value type \"{$valueType}\" is not supported by any serializer");
        }

        $innerValueType = $isListOrMap ? ArrayTypeParser::extractValueType($valueType) : null;
        $nestedValueDefinition = $innerValueType !== null ? $this->createValueFieldDefinition($context, $property, $innerValueType) : null;

        return new Definition(
            FieldDefinition::class,
            [
                '$name' => $property->getName() . '.value',
                '$type' => $phpType,
                '$allowsNull' => true,
                '$hasDefaultValue' => false,
                '$defaultValue' => null,
                '$serializer' => new Reference($valueSerializer),
                '$valueFieldDefinition' => $nestedValueDefinition,
            ],
        );
    }
}
