<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Shopware\DynamodbDalBundle\Client\Client;
use Shopware\DynamodbDalBundle\Client\Cursor\Cursor;
use Shopware\DynamodbDalBundle\Client\Cursor\CursorNormalizer;
use Shopware\DynamodbDalBundle\Client\Index;
use Shopware\DynamodbDalBundle\Criteria\ExpressionCompiler;
use Shopware\DynamodbDalBundle\Criteria\Filter;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\EntityDefinitionRegistry;
use Shopware\DynamodbDalBundle\Serializer\Field\BackedEnumFieldSerializer;
use Shopware\DynamodbDalBundle\Serializer\Field\BoolFieldSerializer;
use Shopware\DynamodbDalBundle\Serializer\Field\DateTimeFieldSerializer;
use Shopware\DynamodbDalBundle\Serializer\Field\FloatFieldSerializer;
use Shopware\DynamodbDalBundle\Serializer\Field\IntFieldSerializer;
use Shopware\DynamodbDalBundle\Serializer\Field\JsonFieldSerializer;
use Shopware\DynamodbDalBundle\Serializer\Field\ListFieldSerializer;
use Shopware\DynamodbDalBundle\Serializer\Field\MapFieldSerializer;
use Shopware\DynamodbDalBundle\Serializer\Field\StringFieldSerializer;
use Shopware\DynamodbDalBundle\Serializer\Field\UidFieldSerializer;
use Shopware\DynamodbDalBundle\Serializer\Serializer;
use Shopware\DynamodbDalBundle\Tests\Fixtures\Entity\TestEntity;
use Shopware\DynamodbDalBundle\Tests\Fixtures\Entity\TestStatus;
use Shopware\DynamodbDalBundle\Tests\Fixtures\TestKernel;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Compiles the bundle inside a real kernel: the container wiring and the compiler pass are the
 * parts a unit test cannot cover.
 */
#[CoversNothing]
class BundleIntegrationTest extends TestCase
{
    private static ?TestKernel $kernel = null;

    public static function setUpBeforeClass(): void
    {
        $cacheDir = \sprintf('%s/dynamodb-dal-bundle', sys_get_temp_dir());
        if (is_dir($cacheDir)) {
            self::removeDirectory($cacheDir);
        }

        self::$kernel = new TestKernel('test', true);
        self::$kernel->boot();
    }

    public static function tearDownAfterClass(): void
    {
        self::$kernel?->shutdown();
        self::$kernel = null;
    }

    public function testDalServicesAreWired(): void
    {
        $container = $this->container();

        static::assertInstanceOf(Client::class, $container->get('test.' . Client::class));
        static::assertInstanceOf(Serializer::class, $container->get('test.' . Serializer::class));
        static::assertInstanceOf(ExpressionCompiler::class, $container->get('test.' . ExpressionCompiler::class));
        static::assertInstanceOf(CursorNormalizer::class, $container->get('test.' . CursorNormalizer::class));
    }

    public function testEntityDefinitionIsCompiledFromTheAttributes(): void
    {
        $definition = $this->definition();

        static::assertSame('test', $definition->getName());
        static::assertSame('test-table', $definition->getTable());
        static::assertSame(TestEntity::class, $definition->getClass());
        static::assertNull($definition->getNormalizer());
        static::assertSame('id', $definition->getKeySchema()->hashKey);
        static::assertSame('createdAt', $definition->getKeySchema()->rangeKey);

        $index = $definition->getIndex('status-index');
        static::assertNotNull($index);
        static::assertSame('status', $index->keySchema->hashKey);
        static::assertSame('createdAt', $index->keySchema->rangeKey);
    }

    public function testEveryFieldGetsTheMatchingSerializer(): void
    {
        $definition = $this->definition();

        $expected = [
            'id' => UidFieldSerializer::class,
            'createdAt' => DateTimeFieldSerializer::class,
            'status' => BackedEnumFieldSerializer::class,
            'name' => StringFieldSerializer::class,
            'counter' => IntFieldSerializer::class,
            'active' => BoolFieldSerializer::class,
            'amount' => FloatFieldSerializer::class,
            'tags' => ListFieldSerializer::class,
            'quantities' => MapFieldSerializer::class,
            'nested' => MapFieldSerializer::class,
            'payload' => JsonFieldSerializer::class,
        ];

        static::assertSame(array_keys($expected), $definition->getFieldNames());

        foreach ($expected as $field => $serializer) {
            $fieldDefinition = $definition->getFieldDefinition($field);
            static::assertNotNull($fieldDefinition, "field {$field}");
            static::assertInstanceOf($serializer, $fieldDefinition->getSerializer(), "field {$field}");
        }

        // A nested `array<string, list<string>>` compiles a value definition per level.
        $nestedValue = $definition->getFieldDefinition('nested')?->getValueFieldDefinition();
        static::assertNotNull($nestedValue);
        static::assertInstanceOf(ListFieldSerializer::class, $nestedValue->getSerializer());
        static::assertInstanceOf(StringFieldSerializer::class, $nestedValue->getValueFieldDefinition()?->getSerializer());
    }

    public function testRegistryResolvesByNameTableAndEntityClass(): void
    {
        $registry = $this->container()->get('test.' . EntityDefinitionRegistry::class);
        static::assertInstanceOf(EntityDefinitionRegistry::class, $registry);

        static::assertTrue($registry->has('test'));
        static::assertSame('test', $registry->get('test')->getName());
        static::assertSame('test', $registry->getByTableName('test-table')->getName());
        static::assertSame('test', $registry->getByEntityClass(TestEntity::class)->getName());
    }

    public function testEntityIsNotAutowirableAsAService(): void
    {
        static::assertFalse($this->container()->has(TestEntity::class));
    }

    public function testSerializerRoundTripsAnEntity(): void
    {
        $serializer = $this->container()->get('test.' . Serializer::class);
        static::assertInstanceOf(Serializer::class, $serializer);

        $definition = $this->definition();

        $entity = new TestEntity();
        $entity->id = Uuid::v7();
        $entity->createdAt = new \DateTimeImmutable('@1700000000');
        $entity->status = TestStatus::Closed;
        $entity->name = 'a name';
        $entity->counter = 42;
        $entity->active = false;
        $entity->amount = 19.99;
        $entity->tags = ['one', 'two'];
        $entity->quantities = ['a' => 1];
        $entity->nested = ['a' => ['x']];
        $entity->payload = ['key' => 'value'];

        $item = $serializer->serialize($definition, $entity)->getFields();

        static::assertSame($entity->id->toString(), $item['id']->getS());
        static::assertSame('1700000000', $item['createdAt']->getN());
        static::assertSame('closed', $item['status']->getS());
        static::assertSame('{"key":"value"}', $item['payload']->getS());

        $restored = $serializer->deserialize($definition, $item);

        static::assertInstanceOf(TestEntity::class, $restored);
        static::assertEquals($entity->getVars(), $restored->getVars());
    }

    public function testCriteriaCompileAgainstTheCompiledDefinition(): void
    {
        $compiler = $this->container()->get('test.' . ExpressionCompiler::class);
        static::assertInstanceOf(ExpressionCompiler::class, $compiler);

        $result = $compiler->compile($this->definition(), Filter::and(
            Filter::equals('status', TestStatus::Open),
            Filter::or(
                Filter::greaterThan('counter', 1),
                Filter::beginsWith('name', 'a'),
            ),
            Filter::equals('nested.a[0]', 'x'),
        ));

        static::assertNotNull($result->expression);
        static::assertStringContainsString(' AND ', $result->expression);
        static::assertStringContainsString(' OR ', $result->expression);
        static::assertStringContainsString('#nested.#a[0]', $result->expression);
        static::assertArrayHasKey('#status', $result->names);
        static::assertCount(4, $result->values);
    }

    public function testCursorNormalizerRoundTripsACursor(): void
    {
        $normalizer = $this->container()->get('test.' . CursorNormalizer::class);
        static::assertInstanceOf(CursorNormalizer::class, $normalizer);

        $id = Uuid::v7();
        $createdAt = new \DateTimeImmutable('@1700000000');

        $cursor = new Cursor(
            'test',
            new Index($id, $createdAt),
            new Index(TestStatus::Open, $createdAt, 'status-index'),
        );

        $restored = $normalizer->denormalize($normalizer->normalize($cursor), Cursor::class);

        static::assertInstanceOf(Cursor::class, $restored);
        static::assertSame('test', $restored->table);
        static::assertEquals($id, $restored->primaryKey->hashValue);
        static::assertEquals($createdAt, $restored->primaryKey->rangeValue);
        static::assertSame('status-index', $restored->indexKey?->index);
        static::assertSame(TestStatus::Open, $restored->indexKey?->hashValue);
    }

    private static function removeDirectory(string $directory): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            \assert($file instanceof \SplFileInfo);
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($directory);
    }

    private function container(): ContainerInterface
    {
        $kernel = self::$kernel;
        static::assertNotNull($kernel);

        return $kernel->getContainer();
    }

    /**
     * @return EntityDefinition<TestEntity>
     */
    private function definition(): EntityDefinition
    {
        $definition = $this->container()->get('dal.definition.test');
        static::assertInstanceOf(EntityDefinition::class, $definition);

        /** @var EntityDefinition<TestEntity> $definition */
        return $definition;
    }
}
