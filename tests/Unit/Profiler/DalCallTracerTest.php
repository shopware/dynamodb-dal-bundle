<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Profiler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\DynamodbDalBundle\Profiler\CallStampingHttpClient;
use Shopware\DynamodbDalBundle\Profiler\DalCall;
use Shopware\DynamodbDalBundle\Profiler\DalCallTracer;
use Shopware\DynamodbDalBundle\Profiler\DynamoDbRequest;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\TraceableHttpClient;

#[CoversClass(DalCallTracer::class)]
#[CoversClass(DalCall::class)]
#[CoversClass(CallStampingHttpClient::class)]
class DalCallTracerTest extends TestCase
{
    public function testACallIsAttributedToTheLineThatEnteredTheDal(): void
    {
        $tracer = new DalCallTracer();

        $call = $tracer->open([\stdClass::class]); $line = __LINE__;

        static::assertInstanceOf(DalCall::class, $call);
        static::assertSame('open', $call->method);
        static::assertSame([\stdClass::class], $call->entities);
        static::assertSame(self::class, $call->callerClass);
        static::assertSame(__FUNCTION__, $call->callerMethod);
        static::assertSame(__FILE__, $call->callerFile);
        static::assertSame($line, $call->callerLine);
    }

    public function testACallABuiltInMakesIsAttributedToTheLineOfTheBuiltIn(): void
    {
        $tracer = new DalCallTracer();

        $calls = array_map($tracer->open(...), [[]]); $line = __LINE__;

        static::assertInstanceOf(DalCall::class, $calls[0]);
        static::assertSame(__FUNCTION__, $calls[0]->callerMethod);
        static::assertSame($line, $calls[0]->callerLine);
    }

    public function testACallMadeDuringAnotherIsPartOfIt(): void
    {
        $tracer = new DalCallTracer();

        $outer = $tracer->open([]);
        $inner = $tracer->run($outer, static fn (): ?DalCall => $tracer->open([]));

        static::assertNull($inner);
        static::assertSame([$outer], $tracer->calls());
    }

    public function testEveryStepAddsItsTimeAndTheFirstFailureIsKept(): void
    {
        $tracer = new DalCallTracer();
        $call = $tracer->open([]);
        static::assertInstanceOf(DalCall::class, $call);

        $tracer->run($call, static function (): void {
            usleep(2_000);
        });
        foreach (['first', 'second'] as $message) {
            try {
                $tracer->run($call, static fn (): never => throw new \RuntimeException($message));
            } catch (\RuntimeException) {
            }
        }

        static::assertGreaterThanOrEqual(2_000_000, $call->durationNs);
        static::assertSame(['class' => \RuntimeException::class, 'message' => 'first'], $call->failure);
    }

    public function testNothingIsRecordedWhileTheProfilerIsDisabled(): void
    {
        $tracer = new DalCallTracer(disabled: static fn (): bool => true);

        static::assertNull($tracer->open([]));
        static::assertNull($tracer->stamp());
        static::assertSame([], $tracer->calls());
    }

    public function testARequestIsFiledUnderTheCallThatSentIt(): void
    {
        $traced = new TraceableHttpClient(new MockHttpClient(static fn (): MockResponse => new MockResponse('{"Count":3,"Items":[]}')));
        $tracer = new DalCallTracer($traced);
        $client = new CallStampingHttpClient($traced, $tracer);

        $call = $tracer->open([]);
        static::assertInstanceOf(DalCall::class, $call);
        $tracer->run($call, static fn (): array => $client->request('POST', 'http://dynamodb.local', self::dynamoDb('Query'))->toArray());
        $client->request('POST', 'http://dynamodb.local', self::dynamoDb('GetItem'))->getContent();
        $client->request('GET', 'http://example.com')->getContent();

        static::assertSame(['Query'], array_map(static fn (DynamoDbRequest $request): string => $request->operation, $call->requests));
        static::assertSame(3, $call->requests[0]->items);
        static::assertSame(['GetItem'], array_map(static fn (DynamoDbRequest $request): string => $request->operation, $tracer->outsideRequests()));
    }

    /**
     * Symfony's HTTP client collector empties the traced client at every collect, sub-requests included
     */
    public function testACallKeepsItsRequestsOnceTheTracedClientIsEmptied(): void
    {
        $traced = new TraceableHttpClient(new MockHttpClient(static fn (): MockResponse => new MockResponse('{}')));
        $tracer = new DalCallTracer($traced);
        $client = new CallStampingHttpClient($traced, $tracer);

        $call = $tracer->open([]);
        static::assertInstanceOf(DalCall::class, $call);
        $tracer->run($call, static fn (): string => $client->request('POST', 'http://dynamodb.local', self::dynamoDb('PutItem'))->getContent());
        $client->request('POST', 'http://dynamodb.local', self::dynamoDb('GetItem'))->getContent();
        $tracer->outsideRequests();

        $traced->reset();

        static::assertCount(1, $call->requests);
        static::assertCount(1, $tracer->outsideRequests());
    }

    public function testTheProfileCollectedLastIsTheMainRequests(): void
    {
        $tracer = new DalCallTracer();

        $subRequest = $tracer->collected();
        $mainRequest = $tracer->collected();

        static::assertFalse($tracer->isLastCollected($subRequest));
        static::assertTrue($tracer->isLastCollected($mainRequest));
    }

    public function testResetForgetsEverything(): void
    {
        $traced = new TraceableHttpClient(new MockHttpClient(static fn (): MockResponse => new MockResponse('{}')));
        $tracer = new DalCallTracer($traced);
        $client = new CallStampingHttpClient($traced, $tracer);

        $tracer->open([]);
        $client->request('POST', 'http://dynamodb.local', self::dynamoDb('GetItem'))->getContent();
        $collect = $tracer->collected();

        $tracer->reset();

        static::assertSame([], $tracer->calls());
        static::assertSame([], $tracer->outsideRequests());
        static::assertFalse($tracer->isLastCollected($collect));
    }

    /**
     * @return array<string, mixed>
     */
    private static function dynamoDb(string $operation): array
    {
        return [
            'headers' => ['X-Amz-Target' => 'DynamoDB_20120810.' . $operation],
            'body' => '{"TableName":"t"}',
        ];
    }
}
