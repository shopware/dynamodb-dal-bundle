<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle;

use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\EntityDefinitionRegistry;
use Shopware\DynamodbDalBundle\Serializer\Field\AbstractFieldSerializer;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * @internal
 */
class DefinitionCompilerPass implements CompilerPassInterface
{
    public const string ENTITIES_PARAMETER = 'shopware_dynamodb_dal.entities';

    public function process(ContainerBuilder $container): void
    {
        // symfony.noFindTaggedServiceIdsCall: intended here, this is build time resolution
        $builder = new DefinitionBuilder($container->findTaggedServiceIds(AbstractFieldSerializer::class));

        foreach ($this->configuredEntities($container) as $entityClass => $table) {
            // An entities are not services, remove them just in case
            $container->removeDefinition($entityClass);

            [$itemName, $definition] = $builder->build($entityClass, $table);

            $container
                ->setDefinition("dal.definition.{$itemName}", $definition)
                ->setPublic(true)
                ->addTag('dal.definition', ['name' => $itemName]);
        }

        $container->setDefinition(EntityDefinitionRegistry::class, new Definition(
            EntityDefinitionRegistry::class,
            ['$definitions' => new TaggedIteratorArgument('dal.definition', indexAttribute: 'name', needsIndexes: true)],
        ));
    }

    /**
     * @return array<class-string, string> - entity class => physical table name
     */
    private function configuredEntities(ContainerBuilder $container): array
    {
        if (!$container->hasParameter(self::ENTITIES_PARAMETER)) {
            return [];
        }

        $entities = $container->getParameter(self::ENTITIES_PARAMETER);
        $container->getParameterBag()->remove(self::ENTITIES_PARAMETER);

        /** @var array<class-string, string> $entities */
        $entities = \is_array($entities) ? $entities : [];

        return $entities;
    }
}
