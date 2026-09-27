<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Profiler;

use Symfony\Component\HttpClient\TraceableHttpClient;
use Symfony\Component\Stopwatch\Stopwatch;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Records the calls the application makes into the DAL for {@see DynamoDbDataCollector}.
 *
 * {@see TraceableReaderClient} and {@see TraceableWriterClient} open a call where the application enters the DAL and
 * run its work through {@see run()}. The DynamoDB requests sent meanwhile carry a stamp from
 * {@see CallStampingHttpClient}, by which they are read back off Symfony's traced HTTP client as soon as the step is
 * done. So a call keeps its requests even once Symfony's HTTP client collector has emptied that client, as it does
 * at every collect.
 *
 * @internal
 */
final class DalCallTracer implements ResetInterface
{
    /**
     * @var list<DalCall>
     */
    private array $calls = [];

    private ?DalCall $current = null;

    /**
     * The requests the running step sent
     *
     * @var list<int>
     */
    private array $stepStamps = [];

    /**
     * The requests sent outside any call that were not read yet
     *
     * @var list<int>
     */
    private array $outsideStamps = [];

    /**
     * @var list<DynamoDbRequest>
     */
    private array $outsideRequests = [];

    private int $stamps = 0;

    private int $collects = 0;

    /**
     * @param ?TraceableHttpClient $httpClient - the traced `aws.base-client`, without which no request is seen
     * @param ?\Closure(): bool $disabled - whether the profiler is disabled, as Symfony's traced HTTP client asks it
     */
    public function __construct(
        private readonly ?TraceableHttpClient $httpClient = null,
        private readonly ?\Closure $disabled = null,
        private readonly ?Stopwatch $stopwatch = null,
    ) {
    }

    /**
     * Opens a call for the DAL method the application is calling.
     *
     * @param list<class-string> $entities - the entity classes the call reads or writes
     *
     * @return ?DalCall - `null` inside another call, whose time and requests this one's are, or while the profiler is disabled
     */
    public function open(array $entities): ?DalCall
    {
        if ($this->current !== null || $this->isDisabled()) {
            return null;
        }

        return $this->calls[] = DalCall::enter(array_values(array_unique($entities)));
    }

    /**
     * Runs one step of a call's work and adds its wall time to the call — AsyncAws and the network included. An eager
     * call is one step. A streamed read steps from item to item, so the time the application spends on each item is
     * not the call's.
     *
     * @template TResult
     *
     * @param callable(): TResult $step
     *
     * @return TResult
     */
    public function run(?DalCall $call, callable $step): mixed
    {
        if ($call === null || $this->current !== null) {
            return $step();
        }

        $this->current = $call;
        $event = $this->stopwatch?->start('dynamodb.' . $call->method, 'dynamodb');
        $startedAt = hrtime(true);
        $failure = null;

        try {
            return $step();
        } catch (\Throwable $exception) {
            $failure = $exception;

            throw $exception;
        } finally {
            $durationNs = hrtime(true) - $startedAt;
            $event?->stop();
            $this->current = null;

            $call->record($durationNs, $this->read($this->stepStamps), $failure);
            $this->stepStamps = [];
        }
    }

    /**
     * A stamp for a DynamoDB request about to be sent, filed under the running call if there is one.
     *
     * @return ?int - `null` while the profiler is disabled
     */
    public function stamp(): ?int
    {
        if ($this->isDisabled()) {
            return null;
        }

        $stamp = ++$this->stamps;
        if ($this->current !== null) {
            $this->stepStamps[] = $stamp;
        } else {
            $this->outsideStamps[] = $stamp;
        }

        return $stamp;
    }

    /**
     * @return list<DalCall>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    /**
     * The DynamoDB requests sent outside any call, e.g. by the application through AsyncAws directly. They are read
     * off the traced HTTP client only now, so this has to run before Symfony's HTTP client collector empties it.
     *
     * @return list<DynamoDbRequest>
     */
    public function outsideRequests(): array
    {
        $this->outsideRequests = [...$this->outsideRequests, ...$this->read($this->outsideStamps)];
        $this->outsideStamps = [];

        return $this->outsideRequests;
    }

    public function isTracingRequests(): bool
    {
        return $this->httpClient !== null;
    }

    /**
     * Counts a profile as collected. A request's sub-requests are collected before it, so the profile collected
     * last is the main request's, see {@see isLastCollected()}.
     */
    public function collected(): int
    {
        return ++$this->collects;
    }

    public function isLastCollected(int $collect): bool
    {
        return $collect === $this->collects;
    }

    public function reset(): void
    {
        $this->calls = [];
        $this->current = null;
        $this->stepStamps = [];
        $this->outsideStamps = [];
        $this->outsideRequests = [];
        $this->stamps = 0;
        $this->collects = 0;
    }

    /**
     * @param list<int> $stamps
     *
     * @return list<DynamoDbRequest>
     */
    private function read(array $stamps): array
    {
        if ($stamps === [] || $this->httpClient === null) {
            return [];
        }

        $requests = [];
        foreach ($this->httpClient->getTracedRequests() as $trace) {
            if (\is_array($trace) && \in_array(DynamoDbRequest::stamp($trace), $stamps, true)) {
                $requests[] = DynamoDbRequest::fromTrace($trace);
            }
        }

        return $requests;
    }

    private function isDisabled(): bool
    {
        return $this->disabled !== null && ($this->disabled)() === true;
    }
}
