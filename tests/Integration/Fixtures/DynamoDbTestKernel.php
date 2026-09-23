<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Integration\Fixtures;

use AsyncAws\DynamoDb\DynamoDbClient;
use Shopware\DynamodbDalBundle\Client\Client;
use Shopware\DynamodbDalBundle\Client\Cursor\CursorNormalizer;
use Shopware\DynamodbDalBundle\Client\ReaderClient;
use Shopware\DynamodbDalBundle\Client\WriterClient;
use Shopware\DynamodbDalBundle\Expression\ExpressionCompiler;
use Shopware\DynamodbDalBundle\Definition\EntityDefinitionRegistry;
use Shopware\DynamodbDalBundle\Serializer\Serializer;
use Shopware\DynamodbDalBundle\ShopwareDynamodbDalBundle;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\Entity\ArchiveEntity;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\Entity\NormalizedEntity;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\Entity\RecordEntity;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

/**
 * The application the DynamoDB-backed suites run in: this bundle, the fixture entities, and a
 * DynamoDbClient pointed at a local DynamoDB rather than AWS.
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
        ExpressionCompiler::class,
        EntityDefinitionRegistry::class,
        CursorNormalizer::class,
        DynamoDbClient::class,
    ];

    /**
     * Logical entity name => physical table name.
     *
     * @var array<string, string>
     */
    public const array TABLES = [
        'record' => 'phpunit-record',
        'archive' => 'phpunit-archive',
        'normalized' => 'phpunit-normalized',
    ];

    public function __construct(private readonly string $endpoint)
    {
        parent::__construct('test', true);
    }

    /**
     * @return iterable<BundleInterface>
     */
    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new ShopwareDynamodbDalBundle();
    }

    public function getCacheDir(): string
    {
        return \sprintf('%s/dynamodb-dal-bundle/dynamodb/cache', sys_get_temp_dir());
    }

    public function getLogDir(): string
    {
        return \sprintf('%s/dynamodb-dal-bundle/dynamodb/log', sys_get_temp_dir());
    }

    public function getConfigDir(): string
    {
        return \sprintf('%s/dynamodb-dal-bundle/dynamodb/config', sys_get_temp_dir());
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

        $container->extension('framework', [
            'secret' => 'test',
            'test' => true,
            'http_method_override' => false,
            'php_errors' => ['log' => true],
        ]);

        // Deliberately no `autoconfigure()`: the bundle has to pick the fixture entities up
        // without it.
        $services = $container->services()
            ->defaults()
                ->autowire();

        $services->load('Shopware\\DynamodbDalBundle\\Tests\\Integration\\Fixtures\\Entity\\', '../Fixtures/Entity/');

        $services->set(DynamoDbClient::class)
            ->args([[
                'endpoint' => $this->endpoint,
                'region' => 'eu-central-1',
                'accessKeyId' => 'phpunit',
                'accessKeySecret' => 'phpunit',
            ]])
            ->autowire(false);

        foreach (self::PUBLIC_SERVICES as $id) {
            $services->alias('test.' . $id, $id)->public();
        }
    }
}
