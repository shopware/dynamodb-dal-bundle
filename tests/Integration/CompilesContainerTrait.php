<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Integration;

use AsyncAws\DynamoDb\DynamoDbClient;
use Shopware\DynamodbDalBundle\ShopwareDynamodbDalBundle;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;
use Symfony\Component\DependencyInjection\ParameterBag\EnvPlaceholderParameterBag;

/**
 * Compiles a container holding nothing but this bundle, configured with the entities under test the
 * way an application configures its own. Cheaper than booting a kernel, and it needs no DynamoDB.
 */
trait CompilesContainerTrait
{
    /**
     * The container is read through the dumped class rather than off the builder: a builder resolves a
     * reference by building the service again, so the back-reference every field definition holds to
     * its entity definition would come out as a second instance.
     *
     * @param array<class-string, string> $entities - entity class => physical table name, the bundle's own configuration
     * @param list<class-string> $services - anything the entities reference, such as a normalizer
     * @param list<string> $public - bundle services to expose, readable back as `test.<id>`
     * @param ?\Closure(ContainerBuilder): void $configure - last word on the container before it compiles
     */
    private function compileContainer(
        array $entities,
        array $services = [],
        array $public = [],
        ?\Closure $configure = null,
    ): Container {
        $container = $this->compileContainerBuilder($entities, $services, $public, $configure);

        $class = 'DalTestContainer' . str_replace('.', '', uniqid('', true));
        eval('?>' . new PhpDumper($container)->dump(['class' => $class]));

        /** @var Container $dumped */
        $dumped = new $class();

        return $dumped;
    }

    /**
     * The builder behind {@see compileContainer()}, for a test that needs to see the services a
     * dumped container no longer distinguishes — a private one among them.
     *
     * @param array<class-string, string> $entities - entity class => physical table name, the bundle's own configuration
     * @param list<class-string> $services - anything the entities reference, such as a normalizer
     * @param list<string> $public - bundle services to expose, readable back as `test.<id>`
     * @param ?\Closure(ContainerBuilder): void $configure - last word on the container before it compiles
     */
    private function compileContainerBuilder(
        array $entities,
        array $services = [],
        array $public = [],
        ?\Closure $configure = null,
    ): ContainerBuilder {
        $container = new ContainerBuilder(new EnvPlaceholderParameterBag([
            'kernel.environment' => 'test',
            'kernel.debug' => false,
            'kernel.build_dir' => sys_get_temp_dir(),
        ]));

        $bundle = new ShopwareDynamodbDalBundle();
        $bundle->build($container);

        $extension = $bundle->getContainerExtension();
        static::assertNotNull($extension);
        $container->registerExtension($extension);
        $container->loadFromExtension($extension->getAlias(), ['entities' => $entities]);

        // What the AsyncAws bundle would provide; nothing compiled here ever reaches it.
        $container->register(DynamoDbClient::class)->setSynthetic(true);

        foreach ($services as $service) {
            $container->register($service)->setPublic(true);
        }

        $configure?->__invoke($container);

        foreach ($public as $id) {
            $container->setAlias('test.' . $id, $id)->setPublic(true);
        }

        $container->compile(true);

        return $container;
    }
}
