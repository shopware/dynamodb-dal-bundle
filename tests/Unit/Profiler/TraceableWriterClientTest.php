<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Profiler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\DynamodbDalBundle\Client\Input\BatchWriteInput;
use Shopware\DynamodbDalBundle\Client\Input\DeleteInput;
use Shopware\DynamodbDalBundle\Client\Input\InsertInput;
use Shopware\DynamodbDalBundle\Client\Input\PutInput;
use Shopware\DynamodbDalBundle\Client\Input\UpdateInput;
use Shopware\DynamodbDalBundle\Client\Input\UpsertInput;
use Shopware\DynamodbDalBundle\Client\Key;
use Shopware\DynamodbDalBundle\Client\Output\UpsertOutcome;
use Shopware\DynamodbDalBundle\Client\Write\WriterClient;
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

    public function testAnUpsertIsOneCallOverItsEntity(): void
    {
        $tracer = new DalCallTracer();
        $inner = static::createStub(WriterClient::class);
        $inner->method('upsert')->willReturn(UpsertOutcome::Updated);
        $writer = new TraceableWriterClient($inner, $tracer);

        static::assertSame(UpsertOutcome::Updated, $writer->upsert(new UpsertInput(new NormalEntity(), ['required'])));

        static::assertSame('upsert', $tracer->calls()[0]->method ?? null);
        static::assertSame([NormalEntity::class], $tracer->calls()[0]->entities);
    }

    public function testAWriteThatStoredNothingSaysSoThroughTheDecorator(): void
    {
        $tracer = new DalCallTracer();
        $inner = static::createStub(WriterClient::class);
        $inner->method('insert')->willReturn(false);
        $inner->method('update')->willReturn(false);
        $inner->method('delete')->willReturn(false);
        $writer = new TraceableWriterClient($inner, $tracer);

        static::assertFalse($writer->insert(new InsertInput(new NormalEntity())));
        static::assertFalse($writer->update(new UpdateInput(new Key(NormalEntity::class, 'a'), ['required' => 'req'])));
        static::assertFalse($writer->delete(new DeleteInput(new Key(NormalEntity::class, 'a'))));

        static::assertSame(['insert', 'update', 'delete'], array_map(static fn ($call): string => $call->method, $tracer->calls()));
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
