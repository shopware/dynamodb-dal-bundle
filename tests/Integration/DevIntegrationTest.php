<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Shopware\DynamodbDalBundle\Client\Read\ReaderClient;
use Shopware\DynamodbDalBundle\Client\Write\WriterClient;
use Shopware\DynamodbDalBundle\Profiler\CallStampingHttpClient;
use Shopware\DynamodbDalBundle\Profiler\DalCallTracer;
use Shopware\DynamodbDalBundle\Profiler\DynamoDbDataCollector;
use Shopware\DynamodbDalBundle\Profiler\TraceableReaderClient;
use Shopware\DynamodbDalBundle\Profiler\TraceableWriterClient;
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
        $tracer = $container->get('test.' . DalCallTracer::class);
        static::assertInstanceOf(DalCallTracer::class, $tracer);
        static::assertTrue($tracer->isTracingRequests());

        // The decorators have to take the reader and writer service ids over, or nothing is traced
        static::assertInstanceOf(TraceableReaderClient::class, $container->get('test.' . ReaderClient::class));
        static::assertInstanceOf(TraceableWriterClient::class, $container->get('test.' . WriterClient::class));

        // The stamping client has to sit outside the traced one, or the stamps never reach the trace
        static::assertInstanceOf(CallStampingHttpClient::class, $container->get('test.aws.base-client'));
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
