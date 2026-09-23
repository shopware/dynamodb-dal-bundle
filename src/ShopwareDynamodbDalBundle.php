<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle;

use Symfony\Bundle\FrameworkBundle\DataCollector\AbstractDataCollector;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class ShopwareDynamodbDalBundle extends AbstractBundle
{
    /**
     * An entity is a plain class the application points this bundle at, not a service it registers:
     * listing it here is what puts it in the DAL, and the table it names is where its items live.
     */
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->arrayNode('entities')
                    ->info('Entity class => the DynamoDB table its items are stored in')
                    ->example(['App\Entity\OrderEntity' => '%env(DYNAMODB_TABLE_ORDER)%'])
                    ->normalizeKeys(false)
                    ->useAttributeAsKey('class')
                    ->scalarPrototype()
                        ->info('Physical table name, as DynamoDB knows it — usually an environment variable')
                        ->cannotBeEmpty()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->setParameter(DefinitionCompilerPass::ENTITIES_PARAMETER, $config['entities'] ?? []);

        $container->import('../config/services.php');

        if ($builder->getParameter('kernel.environment') !== 'dev') {
            return;
        }

        // Both are development tooling, and both lean on packages this bundle only suggests.
        if (class_exists(Command::class)) {
            $container->import('../config/commands.php');
        }

        if (class_exists(AbstractDataCollector::class)) {
            $container->import('../config/profiler.php');
        }
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new ServiceTaggingPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 10);
        $container->addCompilerPass(new DefinitionCompilerPass());
    }
}
