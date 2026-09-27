<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Profiler;

use Symfony\Bundle\FrameworkBundle\DataCollector\AbstractDataCollector;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\LateDataCollectorInterface;
use Symfony\Component\VarDumper\Cloner\Data;

/**
 * Collects the calls a request made into the DAL for the Symfony profiler panel: where each came from, the time
 * spent in it and the DynamoDB requests it sent. The calls are recorded by {@see DalCallTracer}.
 *
 * DynamoDB requests are read off Symfony's TraceableHttpClient (`.debug.aws.base-client`), so the application has
 * to route its AsyncAws clients through an HTTP client built on the `aws.base-client` scope
 * (`async_aws.http_client`).
 *
 * Like Symfony's own collectors, a profile holds everything recorded since the request began, so a sub-request's
 * profile, such as the one rendering an error page, repeats the calls made before it.
 *
 * @phpstan-type RequestRow array{
 *     operation: string,
 *     table: ?string,
 *     index: ?string,
 *     payload: Data,
 *     items: ?int,
 *     counted: ?int,
 *     error: ?string,
 * }
 * @phpstan-type CallRow array{
 *     method: string,
 *     entities: list<class-string>,
 *     duration_ms: float,
 *     failure: ?array{class: class-string<\Throwable>, message: string},
 *     caller_class: ?string,
 *     caller_method: ?string,
 *     caller_file: ?string,
 *     caller_line: ?int,
 *     requests: list<RequestRow>,
 * }
 * @phpstan-type CallerRow array{
 *     caller_class: ?string,
 *     caller_method: ?string,
 *     call_count: int,
 *     request_count: int,
 *     duration_ms: float,
 * }
 *
 * @internal
 */
class DynamoDbDataCollector extends AbstractDataCollector implements LateDataCollectorInterface
{
    private int $collect = 0;

    public function __construct(
        private readonly DalCallTracer $tracer,
    ) {
    }

    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
        $this->collect = $this->tracer->collected();
        $this->data = $this->build();
    }

    /**
     * The application can go on calling the DAL once the response is out: from a streamed response or a
     * `kernel.terminate` listener. Only the main request's profile takes those calls in; a sub-request's keeps what it
     * collected.
     */
    public function lateCollect(): void
    {
        if ($this->tracer->isLastCollected($this->collect)) {
            $this->data = $this->build();
        }
    }

    public function reset(): void
    {
        $this->data = [];
        $this->collect = 0;
        $this->tracer->reset();
    }

    public static function getTemplate(): ?string
    {
        return '@ShopwareDynamodbDal/Collector/dynamo_db.html.twig';
    }

    public function getName(): string
    {
        return 'dynamo_db';
    }

    public function getCallCount(): int
    {
        return $this->data['call_count'] ?? 0;
    }

    public function getRequestCount(): int
    {
        return $this->data['request_count'] ?? 0;
    }

    /**
     * The time spent in the DAL, in milliseconds.
     */
    public function getDuration(): float
    {
        return $this->data['duration_ms'] ?? 0.0;
    }

    public function isTracingRequests(): bool
    {
        return $this->data['tracing_requests'] ?? false;
    }

    /**
     * @return list<CallRow>
     */
    public function getCalls(): array
    {
        return $this->data['calls'] ?? [];
    }

    /**
     * @return list<RequestRow>
     */
    public function getOutsideRequests(): array
    {
        return $this->data['outside_requests'] ?? [];
    }

    /**
     * @return list<CallerRow>
     */
    public function getByCaller(): array
    {
        return $this->data['by_caller'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    private function build(): array
    {
        $calls = array_map($this->callRow(...), $this->tracer->calls());
        $outsideRequests = array_map($this->requestRow(...), $this->tracer->outsideRequests());

        $byCaller = [];
        foreach ($calls as $call) {
            $key = ($call['caller_class'] ?? '') . '::' . ($call['caller_method'] ?? '');
            $byCaller[$key] ??= [
                'caller_class' => $call['caller_class'],
                'caller_method' => $call['caller_method'],
                'call_count' => 0,
                'request_count' => 0,
                'duration_ms' => 0.0,
            ];
            ++$byCaller[$key]['call_count'];
            $byCaller[$key]['request_count'] += \count($call['requests']);
            $byCaller[$key]['duration_ms'] += $call['duration_ms'];
        }

        usort($byCaller, static fn (array $a, array $b): int => $b['duration_ms'] <=> $a['duration_ms']);

        return [
            'calls' => $calls,
            'outside_requests' => $outsideRequests,
            'by_caller' => $byCaller,
            'call_count' => \count($calls),
            'request_count' => array_sum(array_map(static fn (array $call): int => \count($call['requests']), $calls)) + \count($outsideRequests),
            'duration_ms' => array_sum(array_column($calls, 'duration_ms')),
            'tracing_requests' => $this->tracer->isTracingRequests(),
        ];
    }

    /**
     * @return CallRow
     */
    private function callRow(DalCall $call): array
    {
        return [
            'method' => $call->method,
            'entities' => $call->entities,
            'duration_ms' => $call->durationNs / 1e6,
            'failure' => $call->failure,
            'caller_class' => $call->callerClass,
            'caller_method' => $call->callerMethod,
            'caller_file' => $call->callerFile,
            'caller_line' => $call->callerLine,
            'requests' => array_map($this->requestRow(...), $call->requests),
        ];
    }

    /**
     * @return RequestRow
     */
    private function requestRow(DynamoDbRequest $request): array
    {
        return [
            'operation' => $request->operation,
            'table' => $request->table,
            'index' => $request->index,
            'payload' => $this->cloneVar($request->payload),
            'items' => $request->items,
            'counted' => $request->counted,
            'error' => $request->error,
        ];
    }
}
