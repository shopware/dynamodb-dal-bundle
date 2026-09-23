<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Profiler;

use Symfony\Bundle\FrameworkBundle\DataCollector\AbstractDataCollector;
use Symfony\Component\HttpClient\TraceableHttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Collects DynamoDB API and DAL serializer activity for a single request and exposes it to the
 * Symfony profiler panel.
 *
 * DynamoDB calls are read from Symfony's TraceableHttpClient (`.debug.aws.base-client`), so the
 * application has to route its AsyncAws clients through an HTTP client built on the
 * `aws.base-client` scope (`async_aws.http_client`). This means paginated `Query`/`Scan` follow-up
 * requests are captured for free — AsyncAws fires them on the same HTTP client that produced the
 * first page.
 *
 * Serializer timings are pushed in by {@see TraceableSerializer} during the request.
 *
 * @internal
 *
 * @codeCoverageIgnore
 */
class DynamoDbDataCollector extends AbstractDataCollector
{
    /**
     * @var list<array<string, mixed>>
     */
    private array $serializerOperations = [];

    public function __construct(
        private readonly ?TraceableHttpClient $httpClient = null,
    ) {
    }

    /**
     * @param array<string, mixed> $operation
     */
    public function addSerializerOperation(array $operation): void
    {
        $this->serializerOperations[] = $operation;
    }

    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
        // Extract eagerly: HttpClientDataCollector::collect() drains the TraceableHttpClient
        // in the same phase, so a deferred lateCollect() would see an empty list.
        // Our priority (260) ensures we're invoked before HttpClientDataCollector (250).
        $dynamoOperations = $this->extractDynamoOperations();

        $byCaller = [];
        foreach ($dynamoOperations as $op) {
            $key = ($op['caller_class'] ?? 'unknown') . '::' . ($op['caller_method'] ?? 'unknown');
            $byCaller[$key]['caller_class'] ??= $op['caller_class'];
            $byCaller[$key]['caller_method'] ??= $op['caller_method'];
            $byCaller[$key]['dynamo_count'] = ($byCaller[$key]['dynamo_count'] ?? 0) + 1;
            $byCaller[$key]['dynamo_time'] = ($byCaller[$key]['dynamo_time'] ?? 0.0) + $op['duration_ms'];
        }

        foreach ($this->serializerOperations as $op) {
            $key = ($op['caller_class'] ?? 'unknown') . '::' . ($op['caller_method'] ?? 'unknown');
            $byCaller[$key]['caller_class'] ??= $op['caller_class'];
            $byCaller[$key]['caller_method'] ??= $op['caller_method'];
            $byCaller[$key]['serializer_count'] = ($byCaller[$key]['serializer_count'] ?? 0) + 1;
            $byCaller[$key]['serializer_time'] = ($byCaller[$key]['serializer_time'] ?? 0.0) + $op['duration_ms'];
        }

        $byCaller = array_map(
            static fn (array $row): array => $row + ['total_time' => ($row['dynamo_time'] ?? 0.0) + ($row['serializer_time'] ?? 0.0)],
            $byCaller,
        );
        uasort($byCaller, static fn (array $a, array $b): int => $b['total_time'] <=> $a['total_time']);

        $this->data = [
            'dynamo_operations' => array_map(
                fn (array $op): array => [
                    ...$op,
                    'request' => $this->cloneVar($op['request']),
                ],
                $dynamoOperations,
            ),
            'serializer_operations' => $this->serializerOperations,
            'by_caller' => $byCaller,
            'total_dynamo_count' => \count($dynamoOperations),
            'total_dynamo_time' => array_sum(array_column($dynamoOperations, 'duration_ms')),
            'total_serializer_count' => \count($this->serializerOperations),
            'total_serializer_time' => array_sum(array_column($this->serializerOperations, 'duration_ms')),
        ];
    }

    public function reset(): void
    {
        $this->data = [];
        $this->serializerOperations = [];
    }

    public static function getTemplate(): ?string
    {
        return '@ShopwareDynamodbDal/Collector/dynamo_db.html.twig';
    }

    public function getName(): string
    {
        return 'dynamo_db';
    }

    public function getTotalDynamoCount(): int
    {
        return $this->data['total_dynamo_count'] ?? 0;
    }

    public function getTotalDynamoTime(): float
    {
        return $this->data['total_dynamo_time'] ?? 0.0;
    }

    public function getTotalSerializerCount(): int
    {
        return $this->data['total_serializer_count'] ?? 0;
    }

    public function getTotalSerializerTime(): float
    {
        return $this->data['total_serializer_time'] ?? 0.0;
    }

    public function getTotalTime(): float
    {
        return $this->getTotalDynamoTime() + $this->getTotalSerializerTime();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getDynamoOperations(): array
    {
        return $this->data['dynamo_operations'] ?? [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getSerializerOperations(): array
    {
        return $this->data['serializer_operations'] ?? [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getByCaller(): array
    {
        return $this->data['by_caller'] ?? [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function extractDynamoOperations(): array
    {
        if ($this->httpClient === null) {
            return [];
        }

        $operations = [];
        foreach ($this->httpClient->getTracedRequests() as $trace) {
            $target = $this->extractAmzTarget($trace['options']['headers'] ?? []);
            if ($target === null || !str_starts_with($target, 'DynamoDB_')) {
                continue;
            }

            $operation = explode('.', $target, 2)[1] ?? $target;
            $request = $this->decodeJson($trace['options']['body'] ?? null) ?? [];
            $content = \is_array($trace['content'] ?? null) ? $trace['content'] : [];
            $info = $trace['info'] ?? [];
            $caller = $trace['options']['extra']['dynamo_caller'] ?? [];

            $operations[] = [
                'operation' => $operation,
                'table' => $request['TableName'] ?? null,
                'index' => $request['IndexName'] ?? null,
                'request' => $request,
                'duration_ms' => isset($info['total_time']) ? (float) $info['total_time'] * 1000 : 0.0,
                'item_count' => $this->extractItemCount($operation, $content),
                'error' => $this->extractError($info, $content),
                'caller_class' => $caller['class'] ?? null,
                'caller_method' => $caller['method'] ?? null,
                'caller_file' => $caller['file'] ?? null,
                'caller_line' => $caller['line'] ?? null,
            ];
        }

        return $operations;
    }

    /**
     * @param iterable<int|string, mixed> $headers
     */
    private function extractAmzTarget(iterable $headers): ?string
    {
        foreach ($headers as $key => $value) {
            $header = \is_int($key) ? (string) $value : $key . ': ' . (\is_array($value) ? ($value[0] ?? '') : (string) $value);
            if (stripos($header, 'x-amz-target:') === 0) {
                $headerPrefixLength = \strlen('x-amz-target:');

                return trim(substr($header, $headerPrefixLength));
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJson(mixed $body): ?array
    {
        if (!\is_string($body) || $body === '') {
            return null;
        }

        try {
            $decoded = json_decode($body, true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $content
     */
    private function extractItemCount(string $operation, array $content): ?int
    {
        if ($operation === 'Query' || $operation === 'Scan') {
            return isset($content['Count']) ? (int) $content['Count'] : null;
        }

        if ($operation === 'GetItem') {
            return empty($content['Item']) ? 0 : 1;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $info
     * @param array<string, mixed> $content
     */
    private function extractError(array $info, array $content): ?string
    {
        $httpCode = (int) ($info['http_code'] ?? 0);
        if ($httpCode < 400) {
            return null;
        }

        if (isset($content['__type']) || isset($content['message']) || isset($content['Message'])) {
            $type = isset($content['__type']) ? (string) $content['__type'] : null;
            $message = (string) ($content['message'] ?? $content['Message'] ?? '');

            return trim(($type !== null ? $type . ': ' : '') . $message);
        }

        return \sprintf('HTTP %d', $httpCode);
    }
}
