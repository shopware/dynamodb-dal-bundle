<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Profiler;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Client\Input\GetInput;
use Shopware\DynamodbDalBundle\Client\Input\QueryInput;
use Shopware\DynamodbDalBundle\Client\Input\RefreshInput;
use Shopware\DynamodbDalBundle\Client\Input\ScanInput;
use Shopware\DynamodbDalBundle\Client\Key;
use Shopware\DynamodbDalBundle\Client\Read\ReaderClient;

/**
 * Decorates the reader to record every call into it with {@see DalCallTracer}.
 *
 * A key read or search is opened where the application asks for it, so it is attributed to the code that built
 * it, not to whatever reads the output later, such as a template.
 *
 * @internal
 */
final class TraceableReaderClient extends ReaderClient
{
    public function __construct(
        private readonly ReaderClient $inner,
        private readonly DalCallTracer $tracer,
    ) {
    }

    public function get(GetInput $input): \Generator
    {
        $call = $this->tracer->open(array_map(static fn (Key $key): string => $key->class, $input->keys));

        return $this->stream($call, $this->inner->get($input));
    }

    public function refresh(RefreshInput $input): void
    {
        $call = $this->tracer->open(array_map(static fn (AbstractEntity $entity): string => $entity::class, $input->entities));

        $this->tracer->run($call, function () use ($input): void {
            $this->inner->refresh($input);
        });
    }

    public function search(ScanInput|QueryInput $query): \Generator
    {
        $call = $this->tracer->open([$query->class]);

        return $this->stream($call, $this->inner->search($query));
    }

    public function count(ScanInput|QueryInput $query): int
    {
        $call = $this->tracer->open([$query->class]);

        return $this->tracer->run($call, fn (): int => $this->inner->count($query));
    }

    /**
     * Runs the source as steps of the call, each up to the next item.
     *
     * @template TKey
     * @template TValue
     *
     * @param \Generator<TKey, TValue> $source
     *
     * @return \Generator<TKey, TValue>
     */
    private function stream(?DalCall $call, \Generator $source): \Generator
    {
        $this->tracer->run($call, $source->current(...));

        while ($source->valid()) {
            yield $source->key() => $source->current();

            $this->tracer->run($call, $source->next(...));
        }
    }
}
