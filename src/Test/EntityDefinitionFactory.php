<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Test;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Attribute\Table;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\DefinitionCompilerPass;
use Shopware\DynamodbDalBundle\Serializer\AbstractNormalizer;
use Shopware\DynamodbDalBundle\Serializer\Field\AbstractFieldSerializer;
use AsyncAws\DynamoDb\DynamoDbClient;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

/**
 * Builds the {@see EntityDefinition} of an entity class outside the container, for a unit test: of a filter or
 * update action of your own, which compiles against one, or of code that handles a DAL exception, which names one.
 *
 * The definition is built exactly as the container builds it, from the entity's attributes, with the bundle's field
 * serializers and the ones given here, which take precedence as they do when registered. A wrongly declared entity
 * fails as it fails the container build.
 */
final class EntityDefinitionFactory
{
    private function __construct()
    {
    }

    /**
     * @template Entity of AbstractEntity
     *
     * @param class-string<Entity> $class
     * @param list<AbstractFieldSerializer> $fieldSerializers - serializers of your own, tried in this order before the bundle's
     * @param ?AbstractNormalizer $normalizer - the normalizer `#[Table]` names; without one, it is built without constructor arguments
     * @param ?string $table - the physical table name, the `#[Table]` name if none is given
     *
     * @throws \LogicException if the entity is declared wrongly, or names a normalizer that needs constructor arguments and none is given
     *
     * @return EntityDefinition<Entity>
     */
    public static function create(string $class, array $fieldSerializers = [], ?AbstractNormalizer $normalizer = null, ?string $table = null): EntityDefinition
    {
        $attribute = class_exists($class) ? new \ReflectionClass($class)->getAttributes(Table::class)[0] ?? null : null;
        $declared = $attribute?->newInstance();

        try {
            $definition = self::build($class, $declared, $fieldSerializers, $normalizer, $table ?? $declared->name ?? $class);
        } catch (\LogicException $e) {
            // What the container build refuses, such as a key that is not a field, is refused here as it is
            throw $e;
        } catch (\Throwable $e) {
            throw new \LogicException("The definition of {$class} could not be built: {$e->getMessage()}", 0, $e);
        }

        if (!$definition instanceof EntityDefinition || $definition->getClass() !== $class) {
            throw new \LogicException("The definition of {$class} could not be built");
        }

        /** @var EntityDefinition<Entity> $definition */
        return $definition;
    }

    /**
     * @param list<AbstractFieldSerializer> $fieldSerializers
     *
     * @throws \Exception
     */
    private static function build(string $class, ?Table $declared, array $fieldSerializers, ?AbstractNormalizer $normalizer, string $table): mixed
    {
        $container = new ContainerBuilder();
        new PhpFileLoader($container, new FileLocator(\dirname(__DIR__, 2) . '/config'))->load('services.php');
        $container->setParameter(DefinitionCompilerPass::ENTITIES_PARAMETER, [$class => $table]);
        $container->addCompilerPass(new DefinitionCompilerPass());

        // The clients need one, but a definition never reaches them
        $container->register(DynamoDbClient::class)->setSynthetic(true);

        foreach ($fieldSerializers as $fieldSerializer) {
            $container->register($fieldSerializer::class, $fieldSerializer::class)
                ->setSynthetic(true)
                ->setPublic(true)
                ->addTag(AbstractFieldSerializer::class);
        }

        if ($declared?->normalizer !== null) {
            $container->register($declared->normalizer, $declared->normalizer)
                ->setSynthetic($normalizer !== null)
                ->setPublic(true);
        }

        $container->compile();

        foreach ($fieldSerializers as $fieldSerializer) {
            $container->set($fieldSerializer::class, $fieldSerializer);
        }

        if ($declared?->normalizer !== null && $normalizer !== null) {
            $container->set($declared->normalizer, $normalizer);
        }

        return $container->get("dal.definition.{$declared?->name}");
    }
}
