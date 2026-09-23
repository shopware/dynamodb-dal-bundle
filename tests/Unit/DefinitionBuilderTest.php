<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\FieldDefinition;
use Shopware\DynamodbDalBundle\Definition\IndexSchema;
use Shopware\DynamodbDalBundle\Definition\KeySchema;
use Shopware\DynamodbDalBundle\DefinitionBuilder;
use Shopware\DynamodbDalBundle\Serializer\Field\DateTimeFieldSerializer;
use Shopware\DynamodbDalBundle\Serializer\Field\IntFieldSerializer;
use Shopware\DynamodbDalBundle\Serializer\Field\ListFieldSerializer;
use Shopware\DynamodbDalBundle\Serializer\Field\MapFieldSerializer;
use Shopware\DynamodbDalBundle\Serializer\Field\StringFieldSerializer;
use Shopware\DynamodbDalBundle\Tests\Unit\Fixtures\AbstractCatalogEntity;
use Shopware\DynamodbDalBundle\Tests\Unit\Fixtures\CatalogEntity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * What the builder produces, read off the definition itself — no container needed, which is the
 * point of it being separate from {@see \Shopware\DynamodbDalBundle\DefinitionCompilerPass}.
 *
 * {@see \Shopware\DynamodbDalBundle\Tests\Integration\DefinitionCompilerPassTest} covers what those
 * definitions instantiate to once a container has compiled and dumped them.
 */
#[CoversClass(DefinitionBuilder::class)]
class DefinitionBuilderTest extends TestCase
{
    private const string TABLE = 'catalog_table';

    private const array SERIALIZERS = [
        StringFieldSerializer::class,
        IntFieldSerializer::class,
        DateTimeFieldSerializer::class,
        ListFieldSerializer::class,
        MapFieldSerializer::class,
    ];

    /**
     * A class entities extend is not an entity: it holds the attributes they inherit, and compiling it
     * would claim the same name and table its subclasses do. Abstractness is read before the attributes
     * are, so a base carrying a perfectly valid `#[Table]` is refused all the same.
     */
    public function testRefusesAnAbstractClass(): void
    {
        $builder = new DefinitionBuilder(self::SERIALIZERS);

        static::expectExceptionObject(new \LogicException(
            'Entity ' . AbstractCatalogEntity::class . ' is abstract; configure the entities extending it instead',
        ));

        $builder->build(AbstractCatalogEntity::class, self::TABLE);
    }

    public function testRefusesAClassThatIsNotAnEntity(): void
    {
        $builder = new DefinitionBuilder(self::SERIALIZERS);

        static::expectExceptionObject(new \LogicException(
            'Entity ' . AbstractEntity::class . ' is configured as an entity but does not extend ' . AbstractEntity::class,
        ));

        $builder->build(AbstractEntity::class, self::TABLE);
    }

    public function testBuildsOneDefinitionNamedByTheAttributeAndTabledByTheCaller(): void
    {
        [$name, $definition] = $this->build(CatalogEntity::class);

        static::assertSame('catalog', $name);
        static::assertSame(EntityDefinition::class, $definition->getClass());
        static::assertSame('catalog', $definition->getArgument('$name'));
        static::assertSame(CatalogEntity::class, $definition->getArgument('$class'));
        // Taken verbatim: the caller decides the table, commonly an %env()% the container resolves.
        static::assertSame(self::TABLE, $definition->getArgument('$table'));
        static::assertNull($definition->getArgument('$normalizer'));
    }

    public function testInlinesTheKeySchemaAndIndexes(): void
    {
        [, $definition] = $this->build(CatalogEntity::class);

        $keySchema = $definition->getArgument('$keySchema');
        static::assertInstanceOf(Definition::class, $keySchema);
        static::assertSame(KeySchema::class, $keySchema->getClass());
        static::assertSame(['$hashKey' => 'tenantId', '$rangeKey' => 'createdAt'], $keySchema->getArguments());

        $indexes = $definition->getArgument('$indexes');
        static::assertIsArray($indexes);
        static::assertSame(['statusIndex'], array_keys($indexes));
        static::assertInstanceOf(Definition::class, $indexes['statusIndex']);
        static::assertSame(IndexSchema::class, $indexes['statusIndex']->getClass());
        static::assertSame(
            ['$name' => 'statusIndex', '$hashKey' => 'status', '$rangeKey' => 'createdAt'],
            $indexes['statusIndex']->getArguments(),
        );
    }

    public function testInlinesAFieldDefinitionPerFieldInDeclarationOrder(): void
    {
        $fields = $this->fieldDefinitions(new DefinitionBuilder(self::SERIALIZERS));

        static::assertSame(['tenantId', 'status', 'createdAt', 'groups'], array_keys($fields));

        $createdAt = $fields['createdAt'];
        static::assertSame(FieldDefinition::class, $createdAt->getClass());
        static::assertSame('createdAt', $createdAt->getArgument('$name'));
        static::assertSame(\DateTimeImmutable::class, $createdAt->getArgument('$type'));
        static::assertFalse($createdAt->getArgument('$allowsNull'));
        static::assertFalse($createdAt->getArgument('$hasDefaultValue'));
        static::assertNull($createdAt->getArgument('$defaultValue'));
        static::assertEquals(new Reference(DateTimeFieldSerializer::class), $createdAt->getArgument('$serializer'));
        static::assertNull($createdAt->getArgument('$valueFieldDefinition'));
    }

    public function testNestsTheValueDefinitionOfACollectionFieldIntoItsField(): void
    {
        // groups is array<string, list<string>>: a map of lists of strings, three definitions deep.
        $groups = $this->fieldDefinitions(new DefinitionBuilder(self::SERIALIZERS))['groups'];

        static::assertSame('array', $groups->getArgument('$type'));
        static::assertTrue($groups->getArgument('$hasDefaultValue'));
        static::assertSame([], $groups->getArgument('$defaultValue'));
        static::assertEquals(new Reference(MapFieldSerializer::class), $groups->getArgument('$serializer'));

        $value = $groups->getArgument('$valueFieldDefinition');
        static::assertInstanceOf(Definition::class, $value);
        static::assertSame('groups.value', $value->getArgument('$name'));
        static::assertSame('array', $value->getArgument('$type'));
        static::assertEquals(new Reference(ListFieldSerializer::class), $value->getArgument('$serializer'));

        $innerValue = $value->getArgument('$valueFieldDefinition');
        static::assertInstanceOf(Definition::class, $innerValue);
        static::assertSame('groups.value', $innerValue->getArgument('$name'));
        static::assertSame('string', $innerValue->getArgument('$type'));
        static::assertEquals(new Reference(StringFieldSerializer::class), $innerValue->getArgument('$serializer'));
        static::assertNull($innerValue->getArgument('$valueFieldDefinition'));
    }

    /**
     * An inlined definition carrying a method call cannot be dumped, so the back-reference every field
     * definition holds to its entity definition has to stay {@see EntityDefinition}'s own business.
     */
    public function testBuildsDefinitionsWithoutMethodCalls(): void
    {
        [, $definition] = $this->build(CatalogEntity::class);

        static::assertSame([], $definition->getMethodCalls());

        foreach ($this->fieldDefinitions(new DefinitionBuilder(self::SERIALIZERS)) as $field) {
            static::assertSame([], $field->getMethodCalls());
        }
    }

    /**
     * Tagged service ids are taken as given; one that does not resolve to a field serializer is passed
     * over rather than called for a `supports()` it does not have.
     */
    public function testSkipsServiceIdsThatAreNotFieldSerializers(): void
    {
        $fields = $this->fieldDefinitions(new DefinitionBuilder([\stdClass::class, ...self::SERIALIZERS]));

        static::assertEquals(new Reference(StringFieldSerializer::class), $fields['tenantId']->getArgument('$serializer'));
    }

    public function testFieldWithoutASupportingSerializerFailsTheBuild(): void
    {
        $builder = new DefinitionBuilder([StringFieldSerializer::class]);

        static::expectException(\LogicException::class);
        static::expectExceptionMessage('Entity property ' . CatalogEntity::class . '::$createdAt is not supported by any serializer');

        $builder->build(CatalogEntity::class, self::TABLE);
    }

    /**
     * @param class-string $entityClass
     *
     * @return array{string, Definition}
     */
    private function build(string $entityClass, ?DefinitionBuilder $builder = null): array
    {
        $built = ($builder ?? new DefinitionBuilder(self::SERIALIZERS))->build($entityClass, self::TABLE);
        static::assertNotNull($built);

        return $built;
    }

    /**
     * @return array<string, Definition>
     */
    private function fieldDefinitions(DefinitionBuilder $builder): array
    {
        [, $definition] = $this->build(CatalogEntity::class, $builder);

        $fields = $definition->getArgument('$fieldDefinitions');
        static::assertIsArray($fields);

        /** @var array<string, Definition> $fields */
        return $fields;
    }
}
