<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Profiler;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Client\Input\BatchWriteInput;
use Shopware\DynamodbDalBundle\Client\Input\DeleteInput;
use Shopware\DynamodbDalBundle\Client\Input\PutInput;
use Shopware\DynamodbDalBundle\Client\Input\TransactWriteInput;
use Shopware\DynamodbDalBundle\Client\Input\UpdateInput;
use Shopware\DynamodbDalBundle\Client\Key;
use Shopware\DynamodbDalBundle\Client\Write\WriterClient;

/**
 * Decorates the writer to record every call into it with {@see DalCallTracer}. A write that reads its items back
 * does so through the reader, and that read is part of the write's call.
 *
 * @internal
 */
final class TraceableWriterClient extends WriterClient
{
    public function __construct(
        private readonly WriterClient $inner,
        private readonly DalCallTracer $tracer,
    ) {
    }

    public function put(PutInput $input): void
    {
        $this->tracer->run($this->tracer->open([$input->class]), function () use ($input): void {
            $this->inner->put($input);
        });
    }

    public function update(UpdateInput $input): void
    {
        $this->tracer->run($this->tracer->open([$input->class]), function () use ($input): void {
            $this->inner->update($input);
        });
    }

    public function delete(DeleteInput $input): void
    {
        $this->tracer->run($this->tracer->open([$input->class]), function () use ($input): void {
            $this->inner->delete($input);
        });
    }

    public function batchWrite(BatchWriteInput $input): void
    {
        $entities = [
            ...array_map(static fn (AbstractEntity $entity): string => $entity::class, $input->puts),
            ...array_map(static fn (AbstractEntity|Key $key): string => $key instanceof Key ? $key->class : $key::class, $input->deletes),
        ];

        $this->tracer->run($this->tracer->open($entities), function () use ($input): void {
            $this->inner->batchWrite($input);
        });
    }

    public function transactWrite(TransactWriteInput $input): void
    {
        $entities = array_map(static fn (PutInput|UpdateInput|DeleteInput $operation): string => $operation->class, $input->operations);

        $this->tracer->run($this->tracer->open($entities), function () use ($input): void {
            $this->inner->transactWrite($input);
        });
    }
}
