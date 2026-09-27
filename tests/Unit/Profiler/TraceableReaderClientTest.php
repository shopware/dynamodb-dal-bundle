<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Profiler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\DynamodbDalBundle\Client\Input\ScanInput;
use Shopware\DynamodbDalBundle\Client\ReaderClient;
use Shopware\DynamodbDalBundle\Profiler\DalCall;
use Shopware\DynamodbDalBundle\Profiler\DalCallTracer;
use Shopware\DynamodbDalBundle\Profiler\TraceableReaderClient;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\NormalEntity;

#[CoversClass(TraceableReaderClient::class)]
class TraceableReaderClientTest extends TestCase
{
    private DalCallTracer $tracer;

    /**
     * How many items the source produced
     */
    private int $produced = 0;

    protected function setUp(): void
    {
        $this->tracer = new DalCallTracer();
    }

    public function testAStreamIsTimedWithoutTheTimeTheApplicationSpendsOnItsItems(): void
    {
        $reader = $this->reader($this->source(2, microseconds: 10_000));

        foreach ($reader->search(new ScanInput(NormalEntity::class)) as $_) {
            usleep(50_000);
        }

        $durationNs = $this->call()->durationNs;
        static::assertGreaterThanOrEqual(20_000_000, $durationNs);
        static::assertLessThan(100_000_000, $durationNs);
    }

    public function testAStreamIsAttributedToTheCodeThatAskedForIt(): void
    {
        $output = $this->search($this->reader($this->source(1)));

        foreach ($output as $_) {
        }

        static::assertSame('search', $this->call()->method);
        static::assertSame('search', $this->call()->callerMethod);
    }

    public function testAStreamReadsNoFurtherThanTheApplication(): void
    {
        $reader = $this->reader($this->source(3));

        foreach ($reader->search(new ScanInput(NormalEntity::class)) as $_) {
            break;
        }

        static::assertSame(1, $this->produced);
    }

    public function testAFailureWhileStreamingIsTheCallsFailure(): void
    {
        $reader = $this->reader($this->failing());

        try {
            foreach ($reader->search(new ScanInput(NormalEntity::class)) as $_) {
            }
        } catch (\RuntimeException) {
        }

        static::assertSame(['class' => \RuntimeException::class, 'message' => 'Throttled'], $this->call()->failure);
    }

    /**
     * A method the decorator inherits would run on a reader it never built
     */
    public function testEveryPublicMethodOfTheReaderIsDecorated(): void
    {
        foreach (new \ReflectionClass(ReaderClient::class)->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (!$method->isConstructor()) {
                static::assertSame(TraceableReaderClient::class, new \ReflectionMethod(TraceableReaderClient::class, $method->name)->class, $method->name);
            }
        }
    }

    public function testACountIsOneCall(): void
    {
        $inner = static::createStub(ReaderClient::class);
        $inner->method('count')->willReturn(4);

        static::assertSame(4, new TraceableReaderClient($inner, $this->tracer)->count(new ScanInput(NormalEntity::class)));
        static::assertSame('count', $this->call()->method);
    }

    /**
     * The application code that asks for a search, but leaves reading it to its caller
     *
     * @return \Generator<mixed, mixed>
     */
    private function search(TraceableReaderClient $reader): \Generator
    {
        return $reader->search(new ScanInput(NormalEntity::class));
    }

    /**
     * @param \Generator<mixed, mixed> $source
     */
    private function reader(\Generator $source): TraceableReaderClient
    {
        $inner = static::createStub(ReaderClient::class);
        $inner->method('search')->willReturn($source);

        return new TraceableReaderClient($inner, $this->tracer);
    }

    /**
     * @return \Generator<int, NormalEntity>
     */
    private function source(int $items, int $microseconds = 0): \Generator
    {
        for ($i = 0; $i < $items; ++$i) {
            usleep($microseconds);
            ++$this->produced;

            yield new NormalEntity();
        }
    }

    /**
     * @return \Generator<int, NormalEntity>
     */
    private function failing(): \Generator
    {
        yield new NormalEntity();

        throw new \RuntimeException('Throttled');
    }

    private function call(): DalCall
    {
        $calls = $this->tracer->calls();
        static::assertCount(1, $calls);

        return $calls[0];
    }
}
