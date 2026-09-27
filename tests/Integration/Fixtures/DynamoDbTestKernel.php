<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Integration\Fixtures;

use AsyncAws\DynamoDb\DynamoDbClient;
use Shopware\DynamodbDalBundle\Client\Client;
use Shopware\DynamodbDalBundle\Client\ReaderClient;
use Shopware\DynamodbDalBundle\Client\WriterClient;
use Shopware\DynamodbDalBundle\Expression\FilterCompiler;
use Shopware\DynamodbDalBundle\Expression\UpdateCompiler;
use Shopware\DynamodbDalBundle\Definition\EntityDefinitionRegistry;
use Shopware\DynamodbDalBundle\Serializer\Serializer;
use Shopware\DynamodbDalBundle\ShopwareDynamodbDalBundle;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\Entity\ArchiveEntity;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\Entity\NormalizedEntity;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\Entity\RecordEntity;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Bundle\WebProfilerBundle\WebProfilerBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * The application the DynamoDB-backed suites run in: this bundle, the fixture entities, and a
 * DynamoDbClient pointed at a local DynamoDB rather than AWS.
 *
 * In `dev` it is also profiled the way the README sets an application up: AsyncAws sends through the traced
 * `aws.base-client`, and the pages of {@see ProfiledController} are served next to the profiler's own.
 */
class DynamoDbTestKernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * @var list<string>
     */
    public const array PUBLIC_SERVICES = [
        Client::class,
        ReaderClient::class,
        WriterClient::class,
        Serializer::class,
        FilterCompiler::class,
        UpdateCompiler::class,
        EntityDefinitionRegistry::class,
        DynamoDbClient::class,
    ];

    /**
     * The dev-only services on top of those
     *
     * @var list<string>
     */
    public const array DEV_PUBLIC_SERVICES = [
        'profiler',
        'services_resetter',
    ];

    /**
     * Logical name => physical table name.
     *
     * @var array<string, string>
     */
    public const array TABLES = [
        'record' => 'phpunit-record',
        'archive' => 'phpunit-archive',
        'normalized' => 'phpunit-normalized',
    ];

    /**
     * @param 'test'|'dev' $environment
     */
    public function __construct(
        private readonly string $endpoint,
        string $environment = 'test',
    ) {
        parent::__construct($environment, true);
    }

    /**
     * @return iterable<BundleInterface>
     */
    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new ShopwareDynamodbDalBundle();

        if ($this->environment === 'dev') {
            yield new TwigBundle();
            yield new WebProfilerBundle();
        }
    }

    public function getCacheDir(): string
    {
        return \sprintf('%s/dynamodb-dal-bundle/dynamodb/%s/cache', sys_get_temp_dir(), $this->environment);
    }

    public function getLogDir(): string
    {
        return \sprintf('%s/dynamodb-dal-bundle/dynamodb/%s/log', sys_get_temp_dir(), $this->environment);
    }

    public function getConfigDir(): string
    {
        return \sprintf('%s/dynamodb-dal-bundle/dynamodb/%s/config', sys_get_temp_dir(), $this->environment);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        if ($this->environment !== 'dev') {
            return;
        }

        $routes->import('@WebProfilerBundle/Resources/config/routing/wdt.php')->prefix('/_wdt');
        $routes->import('@WebProfilerBundle/Resources/config/routing/profiler.php')->prefix('/_profiler');

        foreach (['put', 'read', 'transact', 'rejected', 'fail', 'streamed', 'outside'] as $action) {
            $routes->add($action, '/' . $action)->controller([ProfiledController::class, $action]);
        }
    }

    protected function configureContainer(ContainerConfigurator $container, LoaderInterface $loader, ContainerBuilder $builder): void
    {
        $container->extension('shopware_dynamodb_dal', [
            'entities' => [
                RecordEntity::class => self::TABLES['record'],
                ArchiveEntity::class => self::TABLES['archive'],
                NormalizedEntity::class => self::TABLES['normalized'],
            ],
        ]);

        $isDev = $this->environment === 'dev';

        $container->extension('framework', [
            'secret' => 'test',
            'test' => true,
            'http_method_override' => false,
            'php_errors' => ['log' => true],
            ...($isDev ? [
                'profiler' => ['enabled' => true, 'collect' => true],
                'http_client' => ['scoped_clients' => ['aws.base-client' => ['scope' => '.*']]],
            ] : []),
        ]);

        // Deliberately no `autoconfigure()`: the bundle has to pick the fixture field serializers
        // up without it.
        $services = $container->services()
            ->defaults()
                ->autowire();

        $services->load('Shopware\\DynamodbDalBundle\\Tests\\Integration\\Fixtures\\Entity\\', '../Fixtures/Entity/');

        $services->set(DynamoDbClient::class)
            ->args([
                [
                    'endpoint' => $this->endpoint,
                    'region' => 'eu-central-1',
                    'accessKeyId' => 'phpunit',
                    'accessKeySecret' => 'phpunit',
                ],
                null,
                $isDev ? service('aws.base-client') : null,
            ])
            ->autowire(false);

        if ($isDev) {
            $services->set(ProfiledController::class)->tag('controller.service_arguments');

            // An error page logs its exception, which would otherwise land in the test output
            $services->set('logger', NullLogger::class);
        }

        foreach ($isDev ? [...self::PUBLIC_SERVICES, ...self::DEV_PUBLIC_SERVICES] : self::PUBLIC_SERVICES as $id) {
            $services->alias('test.' . $id, $id)->public();
        }
    }
}
