<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle;

use Symfony\Bundle\FrameworkBundle\DataCollector\AbstractDataCollector;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class ShopwareDynamodbDalBundle extends AbstractBundle
{
    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
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
