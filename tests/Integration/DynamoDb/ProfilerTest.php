<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Integration\DynamoDb;

use AsyncAws\Core\Exception\Http\ClientException;
use PHPUnit\Framework\Attributes\CoversClass;
use Shopware\DynamodbDalBundle\Profiler\CallStampingHttpClient;
use Shopware\DynamodbDalBundle\Profiler\DalCall;
use Shopware\DynamodbDalBundle\Profiler\DalCallTracer;
use Shopware\DynamodbDalBundle\Profiler\DynamoDbDataCollector;
use Shopware\DynamodbDalBundle\Profiler\DynamoDbRequest;
use Shopware\DynamodbDalBundle\Profiler\TraceableReaderClient;
use Shopware\DynamodbDalBundle\Profiler\TraceableWriterClient;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\DynamoDbTestKernel;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\Entity\RecordEntity;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\ProfiledController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Profiler\Profile;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Requests pages of {@see ProfiledController} from a profiled `dev` kernel and reads what the DynamoDB panel shows
 * of them, so the whole path is real: AsyncAws, the traced `aws.base-client`, and the profile as the profiler stores it.
 */
#[CoversClass(DynamoDbDataCollector::class)]
#[CoversClass(DalCallTracer::class)]
#[CoversClass(DalCall::class)]
#[CoversClass(TraceableReaderClient::class)]
#[CoversClass(TraceableWriterClient::class)]
#[CoversClass(CallStampingHttpClient::class)]
#[CoversClass(DynamoDbRequest::class)]
class ProfilerTest extends DynamoDbTestCase
{
    public function testACallIsAttributedToTheLineThatMadeIt(): void
    {
        [$collector, $line] = $this->profile('/put');

        $call = $collector->getCalls()[0];
        static::assertSame('put', $call['method']);
        static::assertSame([RecordEntity::class], $call['entities']);
        static::assertSame(ProfiledController::class, $call['caller_class']);
        static::assertSame('put', $call['caller_method']);
        static::assertSame(new \ReflectionClass(ProfiledController::class)->getFileName(), $call['caller_file']);
        static::assertSame((int) $line, $call['caller_line']);
        static::assertSame(['PutItem'], array_column($call['requests'], 'operation'));
        static::assertSame(DynamoDbTestKernel::TABLES['record'], $call['requests'][0]['table']);
        static::assertGreaterThan(0.0, $call['duration_ms']);
        static::assertSame($call['duration_ms'], $collector->getDuration());
    }

    public function testAReadIsAttributedToItsCallerHoweverItsOutputIsConsumed(): void
    {
        [$collector, $line] = $this->profile('/read');

        // The first call seeds the table
        $calls = \array_slice($collector->getCalls(), 1);
        static::assertSame(['search', 'find', 'find', 'findMany', 'count'], array_column($calls, 'method'));

        foreach ($calls as $call) {
            static::assertSame(ProfiledController::class, $call['caller_class']);
            static::assertSame('read', $call['caller_method']);
        }

        // iterator_to_array() reads the search, and array_map() calls find() from the line it is called on
        static::assertSame((int) $line, $calls[0]['caller_line']);
        static::assertSame((int) $line + 1, $calls[1]['caller_line']);
    }

    public function testARequestCountsTheItemsItReturned(): void
    {
        [$collector] = $this->profile('/read');

        $requests = array_merge(...array_column(\array_slice($collector->getCalls(), 1), 'requests'));

        static::assertSame(
            [['Query', 2, null], ['GetItem', 1, null], ['GetItem', 1, null], ['BatchGetItem', 2, null], ['Query', null, 2]],
            array_map(static fn (array $request): array => [$request['operation'], $request['items'], $request['counted']], $requests),
        );
    }

    public function testAReadBackIsPartOfTheWriteThatNeedsIt(): void
    {
        [$collector] = $this->profile('/transact');

        $calls = $collector->getCalls();
        static::assertSame(['put', 'transactWrite'], array_column($calls, 'method'));
        static::assertSame(['TransactWriteItems', 'GetItem'], array_column($calls[1]['requests'], 'operation'));
    }

    public function testARejectedRequestNamesTheAwsError(): void
    {
        [$collector] = $this->profile('/rejected');

        $call = $collector->getCalls()[0];
        static::assertSame(ClientException::class, $call['failure']['class'] ?? null);
        static::assertStringStartsWith('ValidationException: ', $call['requests'][0]['error'] ?? '');
    }

    /**
     * The error page is rendered in a sub-request, whose profile is collected before the main request's.
     */
    public function testTheCallsBeforeAnErrorPageStayInTheMainProfile(): void
    {
        [$collector, , $profile] = $this->profile('/fail');

        static::assertCount(1, $profile->getChildren());
        static::assertSame(['put'], array_column($collector->getCalls(), 'method'));
        static::assertSame(1, $collector->getRequestCount());
    }

    public function testAStreamedResponseIsProfiledWithTheCodeThatBuiltItsRead(): void
    {
        [$collector, $content] = $this->profile('/streamed');

        [$ids, $line] = explode(':', $content);
        static::assertSame('ab', $ids);

        $search = $collector->getCalls()[1];
        static::assertSame('search', $search['method']);
        static::assertSame('streamed', $search['caller_method']);
        static::assertSame((int) $line, $search['caller_line']);
        static::assertSame(['Query'], array_column($search['requests'], 'operation'));
        static::assertGreaterThan(0.0, $search['duration_ms']);
    }

    public function testARequestOutsideTheDalIsListedApart(): void
    {
        [$collector] = $this->profile('/outside');

        static::assertSame([], $collector->getCalls());
        static::assertSame(['GetItem'], array_column($collector->getOutsideRequests(), 'operation'));
        static::assertSame(1, $collector->getRequestCount());
        static::assertSame(0.0, $collector->getDuration());
    }

    public function testThePanelAndTheToolbarRender(): void
    {
        [$response] = $this->handle('/read');
        $token = $response->headers->get('X-Debug-Token');
        static::assertIsString($token);

        [$panel, $panelContent] = $this->handle('/_profiler/' . $token . '?panel=dynamo_db');
        static::assertSame(200, $panel->getStatusCode());
        static::assertStringContainsString('ProfiledController::read', $panelContent);

        [$toolbar, $toolbarContent] = $this->handle('/_wdt/' . $token);
        static::assertSame(200, $toolbar->getStatusCode());
        static::assertStringContainsString('DAL calls', $toolbarContent);
    }

    protected static function createKernel(string $endpoint): DynamoDbTestKernel
    {
        return new DynamoDbTestKernel($endpoint, 'dev');
    }

    /**
     * @return array{DynamoDbDataCollector, string, Profile} - the page's collector, its content and its profile
     */
    private function profile(string $path): array
    {
        [$response, $content] = $this->handle($path);

        $profiler = $this->container()->get('test.profiler');
        static::assertInstanceOf(Profiler::class, $profiler);

        $profile = $profiler->loadProfileFromResponse($response);
        static::assertInstanceOf(Profile::class, $profile);

        $collector = $profile->getCollector('dynamo_db');
        static::assertInstanceOf(DynamoDbDataCollector::class, $collector);

        return [$collector, $content, $profile];
    }

    /**
     * @return array{Response, string}
     */
    private function handle(string $path): array
    {
        // The requests setUp() sent to empty the tables belong to no page
        $resetter = $this->container()->get('test.services_resetter');
        static::assertInstanceOf(ResetInterface::class, $resetter);
        $resetter->reset();

        $kernel = $this->kernel();
        $request = Request::create($path);
        $response = $kernel->handle($request);

        // A streamed response runs its callback here, once the profile is collected
        ob_start();
        $response->sendContent();
        $content = (string) ob_get_clean();

        $kernel->terminate($request, $response);

        return [$response, $content];
    }
}
