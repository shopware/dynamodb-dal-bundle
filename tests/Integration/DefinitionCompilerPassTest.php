<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Integration;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Attribute\Table;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\FieldDefinition;
use Shopware\DynamodbDalBundle\Definition\IndexSchema;
use Shopware\DynamodbDalBundle\DefinitionBuilder;
use Shopware\DynamodbDalBundle\DefinitionCompilerPass;
use Shopware\DynamodbDalBundle\ServiceTaggingPass;
use Shopware\DynamodbDalBundle\Serializer\AbstractNormalizer;
use Shopware\DynamodbDalBundle\Serializer\Field\AbstractFieldSerializer;
use Shopware\DynamodbDalBundle\Serializer\Field\BoolFieldSerializer;
use Shopware\DynamodbDalBundle\Serializer\Field\DateTimeFieldSerializer;
use Shopware\DynamodbDalBundle\Serializer\Field\FloatFieldSerializer;
use Shopware\DynamodbDalBundle\Serializer\Field\IntFieldSerializer;
use Shopware\DynamodbDalBundle\Serializer\Field\JsonFieldSerializer;
use Shopware\DynamodbDalBundle\Serializer\Field\ListFieldSerializer;
use Shopware\DynamodbDalBundle\Serializer\Field\MapFieldSerializer;
use Shopware\DynamodbDalBundle\Serializer\Field\StringFieldSerializer;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\CompilerPass\AbstractBaseEntity;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\CompilerPass\EntityWithArrayWithoutDocblockEntity;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\CompilerPass\EntityWithCustomTypeEntity;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\CompilerPass\EntityWithListFieldEntity;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\CompilerPass\EntityWithMapFieldEntity;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\CompilerPass\EntityWithMapUnsupportedValueTypeEntity;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\CompilerPass\EntityWithNestedListFieldEntity;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\CompilerPass\KeyAwareEntity;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\CompilerPass\MissingFieldSerializerEntity;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\CompilerPass\MissingHashKeyEntity;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\CompilerPass\MissingTableAttributeEntity;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\CompilerPass\Money;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\CompilerPass\MoneyFieldSerializer;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\CompilerPass\MissingTableNameEntity;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\CompilerPass\MissingTypeEntity;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\CompilerPass\PrivatePropertyEntity;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\CompilerPass\UnknownHashKeyEntity;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\CompilerPass\UnknownIndexHashKeyEntity;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\CompilerPass\UnknownIndexRangeKeyEntity;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\CompilerPass\UnknownRangeKeyEntity;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\CompilerPass\UnsupportedTypeEntity;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\CompilerPass\ValidEntity;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\CompilerPass\WrongNormalizerEntity;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\Entity\AddressFieldSerializer;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\Entity\ContactEntity;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\Entity\ContactEntityNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;

#[CoversClass(DefinitionCompilerPass::class)]
#[CoversClass(DefinitionBuilder::class)]
#[CoversClass(ServiceTaggingPass::class)]
class DefinitionCompilerPassTest extends TestCase
{
    use CompilesContainerTrait;

    private const string TABLE = 'phpunit_test_table';

    public function testValidEntity(): void
    {
        /** @var EntityDefinition<ValidEntity> $definition */
        $definition = $this->compileDefinition(ValidEntity::class);

        static::assertSame('phpunit_test', $definition->getName());
        static::assertSame('phpunit_test_table', $definition->getTable());
        static::assertSame(ValidEntity::class, $definition->getClass());
        static::assertNull($definition->getNormalizer());
        static::assertSame([
            'stringValue',
            'intValue',
            'floatValue',
            'boolValue',
            'dateTimeValue',
            'nullableValueWithDefault',
            'nullableValue',
        ], $definition->getFieldNames());
        static::assertInstanceOf(ValidEntity::class, $definition->createInstance());

        $fieldDefinitions = $definition->getFieldDefinitions();

        $this->assertValidEntityFieldDefinitions($definition, $fieldDefinitions);
    }

    public function testCompilesKeySchemaAndIndexes(): void
    {
        /** @var EntityDefinition<KeyAwareEntity> $definition */
        $definition = $this->compileDefinition(KeyAwareEntity::class);

        $keySchema = $definition->getKeySchema();
        static::assertSame('tenantId', $keySchema->hashKey);
        static::assertSame('createdAt', $keySchema->rangeKey);
        static::assertSame(['tenantId', 'createdAt'], $keySchema->getFields());

        static::assertSame(['statusCreatedAtIndex', 'companyIdIndex'], array_keys($definition->getIndexes()));

        $statusIndex = $definition->getIndex('statusCreatedAtIndex');
        static::assertInstanceOf(IndexSchema::class, $statusIndex);
        static::assertSame('statusCreatedAtIndex', $statusIndex->name);
        static::assertSame('status', $statusIndex->keySchema->hashKey);
        static::assertSame('createdAt', $statusIndex->keySchema->rangeKey);

        $companyIndex = $definition->getIndex('companyIdIndex');
        static::assertInstanceOf(IndexSchema::class, $companyIndex);
        static::assertSame('companyId', $companyIndex->keySchema->hashKey);
        static::assertNull($companyIndex->keySchema->rangeKey);

        static::assertNull($definition->getIndex('doesNotExist'));
    }

    /**
     * An entity is one service. Its fields, key schema and indexes are values of that service rather
     * than services of their own: nothing ever resolves them on their own, and as separate services
     * the back-reference each field definition holds to its entity definition would be circular.
     */
    public function testRegistersASingleServicePerEntity(): void
    {
        $container = $this->compileContainerBuilder([KeyAwareEntity::class => self::TABLE]);

        $ids = array_values(array_filter(
            $container->getServiceIds(),
            static fn (string $id): bool => str_starts_with($id, 'dal.definition'),
        ));

        static::assertSame(['dal.definition.phpunit_test'], $ids);
    }

    /**
     * The configured table reaches the definition verbatim, so an application points its entity at
     * whichever environment variable it names the table with and the container resolves it.
     */
    public function testTakesTheTableFromTheConfiguration(): void
    {
        $container = $this->compileContainer(
            [ValidEntity::class => '%env(DYNAMODB_TABLE_PHPUNIT)%'],
            configure: static function (ContainerBuilder $container): void {
                $container->setParameter('env(DYNAMODB_TABLE_PHPUNIT)', 'from-the-environment');
            },
        );

        $definition = $container->get('dal.definition.phpunit_test');
        static::assertInstanceOf(EntityDefinition::class, $definition);
        static::assertSame('from-the-environment', $definition->getTable());
    }

    public function testMissingTableAttribute(): void
    {
        static::expectExceptionObject(new \LogicException('Entity ' . MissingTableAttributeEntity::class . ' misses the attribute ' . Table::class));

        $this->compileDefinition(MissingTableAttributeEntity::class);
    }

    public function testMissingTableName(): void
    {
        static::expectExceptionObject(new \LogicException('Entity ' . MissingTableNameEntity::class . ' misses the attribute value ' . Table::class . '::$name'));

        $this->compileDefinition(MissingTableNameEntity::class);
    }

    public function testMissingHashKey(): void
    {
        static::expectExceptionObject(new \LogicException('Entity ' . MissingHashKeyEntity::class . ' misses a required value of ' . Table::class . ' (e.g. $name, $hashKey)'));

        $this->compileDefinition(MissingHashKeyEntity::class);
    }

    public function testUnknownTableHashKey(): void
    {
        static::expectExceptionObject(new \LogicException('Entity ' . UnknownHashKeyEntity::class . ' declares table partition key "doesNotExist" which is not a #[Field] property'));

        $this->compileDefinition(UnknownHashKeyEntity::class);
    }

    public function testUnknownTableRangeKey(): void
    {
        static::expectExceptionObject(new \LogicException('Entity ' . UnknownRangeKeyEntity::class . ' declares table sort key "doesNotExist" which is not a #[Field] property'));

        $this->compileDefinition(UnknownRangeKeyEntity::class);
    }

    public function testUnknownIndexHashKey(): void
    {
        static::expectExceptionObject(new \LogicException('Entity ' . UnknownIndexHashKeyEntity::class . ' declares index \'phpunitIndex\' partition key "doesNotExist" which is not a #[Field] property'));

        $this->compileDefinition(UnknownIndexHashKeyEntity::class);
    }

    public function testUnknownIndexRangeKey(): void
    {
        static::expectExceptionObject(new \LogicException('Entity ' . UnknownIndexRangeKeyEntity::class . ' declares index \'phpunitIndex\' sort key "doesNotExist" which is not a #[Field] property'));

        $this->compileDefinition(UnknownIndexRangeKeyEntity::class);
    }

    public function testWrongNormalizer(): void
    {
        static::expectExceptionObject(new \LogicException('Entity ' . WrongNormalizerEntity::class . ' specifies a normalizer which is not an instance of ' . AbstractNormalizer::class));

        $this->compileDefinition(WrongNormalizerEntity::class);
    }

    public function testPrivatePropertyEntity(): void
    {
        static::expectExceptionObject(new \LogicException('Entity property ' . PrivatePropertyEntity::class . '::$value has to be protected or public'));

        $this->compileDefinition(PrivatePropertyEntity::class);
    }

    public function testMissingTypeEntity(): void
    {
        static::expectExceptionObject(new \LogicException('Entity property ' . MissingTypeEntity::class . '::$value has no type specified'));

        $this->compileDefinition(MissingTypeEntity::class);
    }

    public function testUnsupportedType(): void
    {
        static::expectExceptionObject(new \LogicException('Entity property ' . UnsupportedTypeEntity::class . '::$value specifies a unsupported type ReflectionUnionType'));

        $this->compileDefinition(UnsupportedTypeEntity::class);
    }

    public function testMissingFieldSerializer(): void
    {
        static::expectExceptionObject(new \LogicException('Entity property ' . MissingFieldSerializerEntity::class . '::$value is not supported by any serializer'));

        $this->compileDefinition(MissingFieldSerializerEntity::class);
    }

    public function testEntityWithMapFieldCompilesWithMapFieldSerializer(): void
    {
        /** @var EntityDefinition<EntityWithMapFieldEntity> $definition */
        $definition = $this->compileDefinition(EntityWithMapFieldEntity::class);

        static::assertSame(EntityWithMapFieldEntity::class, $definition->getClass());
        static::assertSame(['id', 'meta'], $definition->getFieldNames());

        $fieldDefinitions = $definition->getFieldDefinitions();

        static::assertArrayHasKey('id', $fieldDefinitions);
        static::assertSame('string', $fieldDefinitions['id']->getType());
        static::assertInstanceOf(StringFieldSerializer::class, $fieldDefinitions['id']->getSerializer());
        static::assertNull($fieldDefinitions['id']->getValueFieldDefinition());

        static::assertArrayHasKey('meta', $fieldDefinitions);
        static::assertSame('array', $fieldDefinitions['meta']->getType());
        static::assertInstanceOf(MapFieldSerializer::class, $fieldDefinitions['meta']->getSerializer());
        $metaValueDef = $fieldDefinitions['meta']->getValueFieldDefinition();
        static::assertNotNull($metaValueDef);
        static::assertSame('meta.value', $metaValueDef->getName());
        static::assertSame('string', $metaValueDef->getType());
        static::assertInstanceOf(StringFieldSerializer::class, $metaValueDef->getSerializer());
    }

    public function testEntityWithListFieldCompilesWithListFieldSerializer(): void
    {
        /** @var EntityDefinition<EntityWithListFieldEntity> $definition */
        $definition = $this->compileDefinition(EntityWithListFieldEntity::class);

        static::assertSame(EntityWithListFieldEntity::class, $definition->getClass());
        static::assertSame(['id', 'tags'], $definition->getFieldNames());

        $fieldDefinitions = $definition->getFieldDefinitions();

        static::assertArrayHasKey('id', $fieldDefinitions);
        static::assertSame('string', $fieldDefinitions['id']->getType());
        static::assertInstanceOf(StringFieldSerializer::class, $fieldDefinitions['id']->getSerializer());

        static::assertArrayHasKey('tags', $fieldDefinitions);
        static::assertSame('array', $fieldDefinitions['tags']->getType());
        static::assertInstanceOf(ListFieldSerializer::class, $fieldDefinitions['tags']->getSerializer());
        $tagsValueDef = $fieldDefinitions['tags']->getValueFieldDefinition();
        static::assertNotNull($tagsValueDef);
        static::assertSame('tags.value', $tagsValueDef->getName());
        static::assertSame('string', $tagsValueDef->getType());
        static::assertInstanceOf(StringFieldSerializer::class, $tagsValueDef->getSerializer());
    }

    public function testArrayFieldWithoutDocblockThrows(): void
    {
        static::expectException(\LogicException::class);
        static::expectExceptionMessage('is not supported by any serializer');

        $this->compileDefinition(EntityWithArrayWithoutDocblockEntity::class);
    }

    public function testMapFieldWithUnsupportedValueTypeThrows(): void
    {
        static::expectException(\LogicException::class);
        static::expectExceptionMessage('is not supported by any serializer');

        $this->compileDefinition(EntityWithMapUnsupportedValueTypeEntity::class);
    }

    public function testEntityWithNestedListAndMapFieldsCompiles(): void
    {
        /** @var EntityDefinition<EntityWithNestedListFieldEntity> $definition */
        $definition = $this->compileDefinition(EntityWithNestedListFieldEntity::class);

        static::assertSame(EntityWithNestedListFieldEntity::class, $definition->getClass());
        static::assertSame(['id', 'matrix', 'groups'], $definition->getFieldNames());

        $fieldDefinitions = $definition->getFieldDefinitions();

        static::assertArrayHasKey('id', $fieldDefinitions);
        static::assertInstanceOf(StringFieldSerializer::class, $fieldDefinitions['id']->getSerializer());
        static::assertNull($fieldDefinitions['id']->getValueFieldDefinition());

        // matrix: list<list<string>> → ListFieldSerializer, value is list<string> → ListFieldSerializer, value is string → StringFieldSerializer
        static::assertArrayHasKey('matrix', $fieldDefinitions);
        static::assertInstanceOf(ListFieldSerializer::class, $fieldDefinitions['matrix']->getSerializer());
        $matrixValueDef = $fieldDefinitions['matrix']->getValueFieldDefinition();
        static::assertNotNull($matrixValueDef);
        static::assertSame('matrix.value', $matrixValueDef->getName());
        static::assertInstanceOf(ListFieldSerializer::class, $matrixValueDef->getSerializer());
        $matrixInnerValueDef = $matrixValueDef->getValueFieldDefinition();
        static::assertNotNull($matrixInnerValueDef);
        static::assertSame('matrix.value', $matrixInnerValueDef->getName());
        static::assertInstanceOf(StringFieldSerializer::class, $matrixInnerValueDef->getSerializer());
        static::assertNull($matrixInnerValueDef->getValueFieldDefinition());

        // groups: array<string, list<string>> → MapFieldSerializer, value is list<string> → ListFieldSerializer, value is string → StringFieldSerializer
        static::assertArrayHasKey('groups', $fieldDefinitions);
        static::assertInstanceOf(MapFieldSerializer::class, $fieldDefinitions['groups']->getSerializer());
        $groupsValueDef = $fieldDefinitions['groups']->getValueFieldDefinition();
        static::assertNotNull($groupsValueDef);
        static::assertSame('groups.value', $groupsValueDef->getName());
        static::assertInstanceOf(ListFieldSerializer::class, $groupsValueDef->getSerializer());
        $groupsInnerValueDef = $groupsValueDef->getValueFieldDefinition();
        static::assertNotNull($groupsInnerValueDef);
        static::assertSame('groups.value', $groupsInnerValueDef->getName());
        static::assertInstanceOf(StringFieldSerializer::class, $groupsInnerValueDef->getSerializer());
        static::assertNull($groupsInnerValueDef->getValueFieldDefinition());
    }

    /**
     * Configuring an entity is all an application does; it registers nothing, and an entity it never
     * configured is simply not part of the DAL.
     */
    public function testCompilesOnlyTheConfiguredEntities(): void
    {
        $container = $this->compileContainer([ValidEntity::class => self::TABLE]);

        static::assertTrue($container->has('dal.definition.phpunit_test'));
        static::assertFalse($container->has(ValidEntity::class));
    }

    /**
     * An application is free to have its entities inside its own service glob. A service of an entity
     * class is a trap — a controller argument typed as one would be autowired to an empty instance
     * instead of being resolved from the request — so the pass takes it back out.
     */
    public function testRemovesAnEntityTheApplicationAlsoRegisteredAsAService(): void
    {
        $container = $this->compileContainerBuilder(
            [ValidEntity::class => self::TABLE],
            configure: static function (ContainerBuilder $container): void {
                $container->register(ValidEntity::class)->setPublic(true);
            },
        );

        static::assertFalse($container->has(ValidEntity::class));
        static::assertTrue($container->has('dal.definition.phpunit_test'));
    }

    public function testAConfiguredClassThatDoesNotExist(): void
    {
        static::expectExceptionObject(new \LogicException('Entity App\Entity\NotHere is configured as an entity but does not exist'));

        /** @phpstan-ignore-next-line argument.type (a class that deliberately does not exist) */
        $this->compileContainer(['App\Entity\NotHere' => self::TABLE]);
    }

    public function testAConfiguredClassThatIsNotAnEntity(): void
    {
        static::expectExceptionObject(new \LogicException('Entity ' . \stdClass::class . ' is configured as an entity but does not extend ' . AbstractEntity::class));

        $this->compileContainer([\stdClass::class => self::TABLE]);
    }

    /**
     * A base its entities extend carries attributes for them to inherit; compiling it would claim the
     * same name and table they do.
     */
    public function testAConfiguredAbstractEntity(): void
    {
        static::expectExceptionObject(new \LogicException('Entity ' . AbstractBaseEntity::class . ' is abstract; configure the entities extending it instead'));

        $this->compileContainer([AbstractBaseEntity::class => self::TABLE]);
    }

    /**
     * The same reach applies to the other extension point: a field serializer an application
     * registered plainly has to be picked up, or an entity using its type will not compile.
     */
    public function testUsesAFieldSerializerTheApplicationRegisteredWithoutATag(): void
    {
        $definition = $this->compileDefinition(EntityWithCustomTypeEntity::class, MoneyFieldSerializer::class);

        $amount = $definition->getFieldDefinition('amount');
        static::assertNotNull($amount);
        static::assertSame(Money::class, $amount->getType());
        static::assertInstanceOf(MoneyFieldSerializer::class, $amount->getSerializer());
    }

    public function testAnEntityWithATypeNoRegisteredSerializerClaimsDoesNotCompile(): void
    {
        static::expectException(\LogicException::class);
        static::expectExceptionMessage('is not supported by any serializer');

        $this->compileDefinition(EntityWithCustomTypeEntity::class);
    }

    public function testAJsonSerializablePropertyWithoutASerializerOfItsOwnIsStoredAsJson(): void
    {
        $address = $this->compileContactDefinition()->getFieldDefinition('address');

        static::assertNotNull($address);
        static::assertInstanceOf(JsonFieldSerializer::class, $address->getSerializer());
    }

    /**
     * The JSON serializer claims every `JsonSerializable`, so the application's serializer for one has
     * to win however the two were registered. Registered from a compiler pass, this one comes after
     * the bundle's own, as it would from a bundle loaded later, and still takes precedence.
     */
    public function testAFieldSerializerRegisteredAfterTheBundlesOwnTakesPrecedenceOverTheJsonOne(): void
    {
        $definition = $this->compileContactDefinition(static function (ContainerBuilder $container): void {
            $container->addCompilerPass(new class implements CompilerPassInterface {
                public function process(ContainerBuilder $container): void
                {
                    $container->register(AddressFieldSerializer::class, AddressFieldSerializer::class);
                }
            }, PassConfig::TYPE_BEFORE_OPTIMIZATION, 100);
        });

        $address = $definition->getFieldDefinition('address');
        static::assertNotNull($address);
        static::assertInstanceOf(AddressFieldSerializer::class, $address->getSerializer());
    }

    /**
     * A serializer the application tagged itself keeps its tag, priority and all: tagged below the
     * JSON serializer, it is tried after it.
     */
    public function testAFieldSerializerTaggedWithAPriorityKeepsIt(): void
    {
        $definition = $this->compileContactDefinition(static function (ContainerBuilder $container): void {
            $container->register(AddressFieldSerializer::class)
                ->addTag(AbstractFieldSerializer::class, ['priority' => -1000]);
        });

        $address = $definition->getFieldDefinition('address');
        static::assertNotNull($address);
        static::assertInstanceOf(JsonFieldSerializer::class, $address->getSerializer());
    }

    /**
     * @param ?\Closure(ContainerBuilder): void $configure
     *
     * @return EntityDefinition<ContactEntity>
     */
    private function compileContactDefinition(?\Closure $configure = null): EntityDefinition
    {
        $container = $this->compileContainer([ContactEntity::class => self::TABLE], [ContactEntityNormalizer::class], configure: $configure);

        $definition = $container->get('dal.definition.contact');
        static::assertInstanceOf(EntityDefinition::class, $definition);

        return $definition;
    }

    /**
     * @param array<string, FieldDefinition> $fieldDefinitions
     */
    private function assertValidEntityFieldDefinitions(EntityDefinition $definition, array $fieldDefinitions): void
    {
        // stringValue
        static::assertCount(7, $fieldDefinitions);
        static::assertArrayHasKey('stringValue', $fieldDefinitions);
        static::assertSame('stringValue', $fieldDefinitions['stringValue']->getName());
        static::assertSame('string', $fieldDefinitions['stringValue']->getType());
        static::assertFalse($fieldDefinitions['stringValue']->allowsNull());
        static::assertFalse($fieldDefinitions['stringValue']->hasDefaultValue());
        static::assertNull($fieldDefinitions['stringValue']->getDefaultValue());
        static::assertInstanceOf(StringFieldSerializer::class, $fieldDefinitions['stringValue']->getSerializer());
        static::assertSame($definition, $fieldDefinitions['stringValue']->getEntityDefinition());

        // intValue
        static::assertArrayHasKey('intValue', $fieldDefinitions);
        static::assertSame('intValue', $fieldDefinitions['intValue']->getName());
        static::assertSame('int', $fieldDefinitions['intValue']->getType());
        static::assertFalse($fieldDefinitions['intValue']->allowsNull());
        static::assertFalse($fieldDefinitions['intValue']->hasDefaultValue());
        static::assertNull($fieldDefinitions['intValue']->getDefaultValue());
        static::assertInstanceOf(IntFieldSerializer::class, $fieldDefinitions['intValue']->getSerializer());
        static::assertSame($definition, $fieldDefinitions['intValue']->getEntityDefinition());

        // floatValue
        static::assertArrayHasKey('floatValue', $fieldDefinitions);
        static::assertSame('floatValue', $fieldDefinitions['floatValue']->getName());
        static::assertSame('float', $fieldDefinitions['floatValue']->getType());
        static::assertFalse($fieldDefinitions['floatValue']->allowsNull());
        static::assertFalse($fieldDefinitions['floatValue']->hasDefaultValue());
        static::assertNull($fieldDefinitions['floatValue']->getDefaultValue());
        static::assertInstanceOf(FloatFieldSerializer::class, $fieldDefinitions['floatValue']->getSerializer());
        static::assertSame($definition, $fieldDefinitions['floatValue']->getEntityDefinition());

        // boolValue
        static::assertArrayHasKey('boolValue', $fieldDefinitions);
        static::assertSame('boolValue', $fieldDefinitions['boolValue']->getName());
        static::assertSame('bool', $fieldDefinitions['boolValue']->getType());
        static::assertFalse($fieldDefinitions['boolValue']->allowsNull());
        static::assertFalse($fieldDefinitions['boolValue']->hasDefaultValue());
        static::assertNull($fieldDefinitions['boolValue']->getDefaultValue());
        static::assertInstanceOf(BoolFieldSerializer::class, $fieldDefinitions['boolValue']->getSerializer());
        static::assertSame($definition, $fieldDefinitions['boolValue']->getEntityDefinition());

        // dateTimeValue
        static::assertArrayHasKey('dateTimeValue', $fieldDefinitions);
        static::assertSame('dateTimeValue', $fieldDefinitions['dateTimeValue']->getName());
        static::assertSame('DateTimeImmutable', $fieldDefinitions['dateTimeValue']->getType());
        static::assertTrue($fieldDefinitions['dateTimeValue']->allowsNull());
        static::assertTrue($fieldDefinitions['dateTimeValue']->hasDefaultValue());
        static::assertNull($fieldDefinitions['dateTimeValue']->getDefaultValue());
        static::assertInstanceOf(DateTimeFieldSerializer::class, $fieldDefinitions['dateTimeValue']->getSerializer());
        static::assertSame($definition, $fieldDefinitions['dateTimeValue']->getEntityDefinition());

        // nullableValueWithDefault
        static::assertArrayHasKey('nullableValueWithDefault', $fieldDefinitions);
        static::assertSame('nullableValueWithDefault', $fieldDefinitions['nullableValueWithDefault']->getName());
        static::assertSame('string', $fieldDefinitions['nullableValueWithDefault']->getType());
        static::assertTrue($fieldDefinitions['nullableValueWithDefault']->allowsNull());
        static::assertTrue($fieldDefinitions['nullableValueWithDefault']->hasDefaultValue());
        static::assertSame('sdf', $fieldDefinitions['nullableValueWithDefault']->getDefaultValue());
        static::assertInstanceOf(StringFieldSerializer::class, $fieldDefinitions['nullableValueWithDefault']->getSerializer());
        static::assertSame($definition, $fieldDefinitions['nullableValueWithDefault']->getEntityDefinition());

        // nullableValue
        static::assertArrayHasKey('nullableValue', $fieldDefinitions);
        static::assertSame('nullableValue', $fieldDefinitions['nullableValue']->getName());
        static::assertSame('string', $fieldDefinitions['nullableValue']->getType());
        static::assertTrue($fieldDefinitions['nullableValue']->allowsNull());
        static::assertFalse($fieldDefinitions['nullableValue']->hasDefaultValue());
        static::assertNull($fieldDefinitions['nullableValue']->getDefaultValue());
        static::assertInstanceOf(StringFieldSerializer::class, $fieldDefinitions['nullableValue']->getSerializer());
        static::assertSame($definition, $fieldDefinitions['nullableValue']->getEntityDefinition());
    }

    /**
     * Every entity passed is configured against the same table; anything else — a field serializer,
     * say — is registered as a plain service, untagged, exactly as an application would.
     *
     * @param class-string ...$classes
     *
     * @return EntityDefinition<AbstractEntity>
     */
    private function compileDefinition(string ...$classes): EntityDefinition
    {
        $isEntity = static fn (string $class): bool => is_subclass_of($class, AbstractEntity::class, true);

        $entities = [];
        foreach (array_filter($classes, $isEntity) as $entityClass) {
            $entities[$entityClass] = self::TABLE;
        }

        $services = array_values(array_filter($classes, static fn (string $class): bool => !$isEntity($class)));

        $definition = $this->compileContainer($entities, $services)->get('dal.definition.phpunit_test');

        static::assertInstanceOf(EntityDefinition::class, $definition);

        return $definition;
    }
}
