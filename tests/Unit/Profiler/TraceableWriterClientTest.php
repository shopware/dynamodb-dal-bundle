<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Profiler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\DynamodbDalBundle\Client\Input\BatchWriteInput;
use Shopware\DynamodbDalBundle\Client\Input\PutInput;
use Shopware\DynamodbDalBundle\Client\Key;
use Shopware\DynamodbDalBundle\Client\WriterClient;
use Shopware\DynamodbDalBundle\Profiler\DalCallTracer;
use Shopware\DynamodbDalBundle\Profiler\TraceableWriterClient;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\NormalEntity;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\OtherEntity;

#[CoversClass(TraceableWriterClient::class)]
class TraceableWriterClientTest extends TestCase
{
    public function testAWriteIsOneCallOverTheEntitiesItWrites(): void
    {
        $tracer = new DalCallTracer();
        $writer = new TraceableWriterClient(static::createStub(WriterClient::class), $tracer);

        $writer->batchWrite(new BatchWriteInput([new NormalEntity(), new NormalEntity()], [new Key(OtherEntity::class, 'a')]));

        $calls = $tracer->calls();
        static::assertCount(1, $calls);
        static::assertSame('batchWrite', $calls[0]->method);
        static::assertSame([NormalEntity::class, OtherEntity::class], $calls[0]->entities);
    }

    public function testAFailedWriteIsTheCallsFailure(): void
    {
        $inner = static::createStub(WriterClient::class);
        $inner->method('put')->willThrowException(new \RuntimeException('Conditional check failed'));
        $tracer = new DalCallTracer();

        try {
            new TraceableWriterClient($inner, $tracer)->put(new PutInput(new NormalEntity()));
        } catch (\RuntimeException) {
        }

        static::assertSame(['class' => \RuntimeException::class, 'message' => 'Conditional check failed'], $tracer->calls()[0]->failure ?? null);
    }

    /**
     * A method the decorator inherits would run on a writer it never built
     */
    public function testEveryPublicMethodOfTheWriterIsDecorated(): void
    {
        foreach (new \ReflectionClass(WriterClient::class)->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (!$method->isConstructor()) {
                static::assertSame(TraceableWriterClient::class, new \ReflectionMethod(TraceableWriterClient::class, $method->name)->class, $method->name);
            }
        }
    }
}
