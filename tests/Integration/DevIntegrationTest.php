<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Shopware\DynamodbDalBundle\Profiler\CallerStampingHttpClient;
use Shopware\DynamodbDalBundle\Profiler\DynamoDbDataCollector;
use Shopware\DynamodbDalBundle\Profiler\TraceableSerializer;
use Shopware\DynamodbDalBundle\Serializer\Serializer;
use Shopware\DynamodbDalBundle\Tests\Fixtures\TestKernel;
use Symfony\Component\Console\CommandLoader\CommandLoaderInterface;
use Twig\Environment;

/**
 * The commands and the profiler integration are `#[When(env: 'dev')]`, so they only exist in a
 * kernel booted for that environment.
 */
#[CoversNothing]
class DevIntegrationTest extends TestCase
{
    private static ?TestKernel $kernel = null;

    public static function setUpBeforeClass(): void
    {
        self::$kernel = new TestKernel('dev', true);
        self::$kernel->boot();
    }

    public static function tearDownAfterClass(): void
    {
        self::$kernel?->shutdown();
        self::$kernel = null;
    }

    public function testCommandsAreRegistered(): void
    {
        $loader = self::$kernel?->getContainer()->get('console.command_loader');
        static::assertInstanceOf(CommandLoaderInterface::class, $loader);

        static::assertTrue($loader->has('dal:definition'));
        static::assertTrue($loader->has('dal:baseline:required-fields'));
        static::assertTrue($loader->has('dal:baseline:table-schema'));
    }

    public function testProfilerIntegrationIsWired(): void
    {
        $container = self::$kernel?->getContainer();
        static::assertNotNull($container);

        static::assertInstanceOf(DynamoDbDataCollector::class, $container->get('test.' . DynamoDbDataCollector::class));
        static::assertInstanceOf(CallerStampingHttpClient::class, $container->get('test.' . CallerStampingHttpClient::class));
        static::assertInstanceOf(TraceableSerializer::class, $container->get('test.' . TraceableSerializer::class));

        // The decorator has to take the Serializer service id over, or nothing is traced.
        static::assertInstanceOf(
            TraceableSerializer::class,
            $container->get('test.' . Serializer::class),
        );
    }

    public function testCollectorTemplateResolvesInsideTheBundle(): void
    {
        $template = DynamoDbDataCollector::getTemplate();
        static::assertSame('@ShopwareDynamodbDal/Collector/dynamo_db.html.twig', $template);

        $twig = self::$kernel?->getContainer()->get('test.twig');
        static::assertInstanceOf(Environment::class, $twig);
        static::assertTrue($twig->getLoader()->exists($template));
    }
}
