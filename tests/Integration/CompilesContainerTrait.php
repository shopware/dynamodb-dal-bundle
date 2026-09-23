<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Integration;

use AsyncAws\DynamoDb\DynamoDbClient;
use Shopware\DynamodbDalBundle\ShopwareDynamodbDalBundle;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;
use Symfony\Component\DependencyInjection\ParameterBag\EnvPlaceholderParameterBag;

/**
 * Compiles a container holding nothing but this bundle and the entity classes under test, registered
 * the way an application registers its own. Cheaper than booting a kernel, and it needs no DynamoDB.
 */
trait CompilesContainerTrait
{
    /**
     * The container is read through the dumped class rather than off the builder: a builder resolves a
     * reference by building the service again, so the back-reference every field definition holds to
     * its entity definition would come out as a second instance.
     *
     * @param array<string, string> $tables - logical entity name => physical table name
     * @param list<class-string> $entityClasses
     * @param list<class-string> $services - anything the entities reference, such as a normalizer
     * @param list<string> $public - bundle services to expose, readable back as `test.<id>`
     * @param ?\Closure(ContainerBuilder): void $configure - last word on the container before it compiles
     */
    private function compileContainer(
        array $tables,
        array $entityClasses,
        array $services = [],
        array $public = [],
        ?\Closure $configure = null,
    ): Container {
        $parameters = [
            'kernel.environment' => 'test',
            'kernel.debug' => false,
        ];
        foreach ($tables as $name => $table) {
            $parameters[\sprintf('env(DYNAMODB_TABLE_%s)', strtoupper($name))] = $table;
        }

        $container = new ContainerBuilder(new EnvPlaceholderParameterBag($parameters));

        $bundle = new ShopwareDynamodbDalBundle();
        $bundle->build($container);

        $extension = $bundle->getContainerExtension();
        static::assertNotNull($extension);
        $container->registerExtension($extension);
        $container->loadFromExtension($extension->getAlias(), []);

        // What the AsyncAws bundle would provide; nothing compiled here ever reaches it.
        $container->register(DynamoDbClient::class)->setSynthetic(true);

        foreach ($services as $service) {
            $container->register($service)->setPublic(true);
        }

        // Registered plain — no tag, no autoconfiguration. Picking these up is the bundle's job.
        foreach ($entityClasses as $entityClass) {
            $container->register($entityClass);
        }

        $configure?->__invoke($container);

        foreach ($public as $id) {
            $container->setAlias('test.' . $id, $id)->setPublic(true);
        }

        $container->compile(true);

        $class = 'DalTestContainer' . str_replace('.', '', uniqid('', true));
        eval('?>' . new PhpDumper($container)->dump(['class' => $class]));

        /** @var Container $dumped */
        $dumped = new $class();

        return $dumped;
    }
}
