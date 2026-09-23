<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Fixtures;

use AsyncAws\DynamoDb\DynamoDbClient;
use Shopware\DynamodbDalBundle\Client\Client;
use Shopware\DynamodbDalBundle\Client\Cursor\CursorNormalizer;
use Shopware\DynamodbDalBundle\Client\ReaderClient;
use Shopware\DynamodbDalBundle\Client\WriterClient;
use Shopware\DynamodbDalBundle\Criteria\ExpressionCompiler;
use Shopware\DynamodbDalBundle\Definition\EntityDefinitionRegistry;
use Shopware\DynamodbDalBundle\Profiler\CallerStampingHttpClient;
use Shopware\DynamodbDalBundle\Profiler\DynamoDbDataCollector;
use Shopware\DynamodbDalBundle\Profiler\TraceableSerializer;
use Shopware\DynamodbDalBundle\Serializer\Serializer;
use Shopware\DynamodbDalBundle\ShopwareDynamodbDalBundle;
use Shopware\DynamodbDalBundle\Tests\Fixtures\Entity\TestEntity;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Bundle\WebProfilerBundle\WebProfilerBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\TraceableHttpClient;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * Minimal application the integration tests compile the bundle in: the framework bundle, this
 * bundle, a stubbed DynamoDB client and the fixture entity plus repository, registered the way an
 * application registers its own `App\` services.
 *
 * In `dev` it also brings the web profiler and the `aws.base-client` scope the DAL profiler
 * integration hooks into, which the AsyncAws bundle provides in a real application.
 */
class TestKernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * Services the tests read that an application would only ever autowire.
     *
     * @var list<string>
     */
    public const array PUBLIC_SERVICES = [
        Client::class,
        ReaderClient::class,
        WriterClient::class,
        Serializer::class,
        ExpressionCompiler::class,
        EntityDefinitionRegistry::class,
        CursorNormalizer::class,
    ];

    /**
     * The dev-only services on top of those, for the same reason.
     *
     * @var list<string>
     */
    public const array DEV_PUBLIC_SERVICES = [
        DynamoDbDataCollector::class,
        CallerStampingHttpClient::class,
        TraceableSerializer::class,
        'twig',
    ];

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
        return \sprintf('%s/dynamodb-dal-bundle/%s/cache', sys_get_temp_dir(), $this->environment);
    }

    /**
     * Kept out of the package: in debug mode the framework bundle dumps a `reference.php` next to
     * the application config, which is an application artifact, not part of this bundle.
     */
    public function getConfigDir(): string
    {
        return \sprintf('%s/dynamodb-dal-bundle/%s/config', sys_get_temp_dir(), $this->environment);
    }

    public function getLogDir(): string
    {
        return \sprintf('%s/dynamodb-dal-bundle/%s/log', sys_get_temp_dir(), $this->environment);
    }

    protected function configureContainer(ContainerConfigurator $container, LoaderInterface $loader, ContainerBuilder $builder): void
    {
        $isDev = $this->environment === 'dev';

        $builder->setParameter('env(DYNAMODB_TABLE_TEST)', 'test-table');

        $container->extension('shopware_dynamodb_dal', [
            'entities' => [TestEntity::class => '%env(DYNAMODB_TABLE_TEST)%'],
        ]);

        $container->extension('framework', [
            'secret' => 'test',
            'test' => true,
            'http_method_override' => false,
            'php_errors' => ['log' => true],
            'profiler' => ['enabled' => $isDev, 'collect' => false],
        ]);

        $services = $container->services()
            ->defaults()
                ->autowire();

        $services->load('Shopware\\DynamodbDalBundle\\Tests\\Fixtures\\', '../Fixtures/');

        // Stands in for the client the AsyncAws bundle would provide; no request is ever sent.
        $services->set(DynamoDbClient::class)
            ->args([['region' => 'eu-central-1', 'accessKeyId' => 'key', 'accessKeySecret' => 'secret']])
            ->autowire(false);

        if ($isDev) {
            // The scope the AsyncAws bundle's `http_client` builds on, which the DAL profiler decorates.
            $services->set('aws.base-client', MockHttpClient::class)->autowire(false);
            $services->set('.debug.aws.base-client', TraceableHttpClient::class)
                ->args([service('aws.base-client')])
                ->autowire(false);
        }

        $publicServices = $isDev
            ? [...self::PUBLIC_SERVICES, ...self::DEV_PUBLIC_SERVICES]
            : self::PUBLIC_SERVICES;

        foreach ($publicServices as $id) {
            $services->alias('test.' . $id, $id)->public();
        }
    }
}
