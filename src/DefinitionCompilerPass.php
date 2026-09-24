<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle;

use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\EntityDefinitionRegistry;
use Shopware\DynamodbDalBundle\Serializer\Field\AbstractFieldSerializer;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PriorityTaggedServiceTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * @internal
 */
class DefinitionCompilerPass implements CompilerPassInterface
{
    use PriorityTaggedServiceTrait;

    public const string ENTITIES_PARAMETER = 'shopware_dynamodb_dal.entities';

    public function process(ContainerBuilder $container): void
    {
        $fieldSerializers = array_map(
            static fn (Reference $reference): string => (string) $reference,
            $this->findAndSortTaggedServices(AbstractFieldSerializer::class, $container),
        );

        $builder = new DefinitionBuilder(array_values($fieldSerializers));

        /** @var array<string, class-string> $classesByName */
        $classesByName = [];
        /** @var array<string, class-string> $classesByTable */
        $classesByTable = [];
        foreach ($this->configuredEntities($container) as $entityClass => $table) {
            // An entities are not services, remove them just in case
            $container->removeDefinition($entityClass);

            [$itemName, $definition] = $builder->build($entityClass, $table);

            // The name keys the service and the registry, so a second entity by the same name would silently replace the first
            if (isset($classesByName[$itemName])) {
                throw new \LogicException("Entities {$classesByName[$itemName]} and {$entityClass} both declare #[Table(name: \"{$itemName}\")]; each entity needs a name of its own");
            }

            $classesByName[$itemName] = $entityClass;

            // An item's entity class is told by its table alone, in a BatchGetItem response as in a scan
            if (isset($classesByTable[$table])) {
                $configured = $container->resolveEnvPlaceholders($table, '%%env(%s)%%');
                $configured = \is_string($configured) ? $configured : $table;

                throw new \LogicException("Entities {$classesByTable[$table]} and {$entityClass} are both stored in table \"{$configured}\"; each entity needs a table of its own");
            }

            $classesByTable[$table] = $entityClass;

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
