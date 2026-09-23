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
 * A traced request is untyped all the way down — headers, body and response content are whatever
 * the wire carried — so every value the panel shows is narrowed out of it here, once, into the two
 * row shapes below.
 *
 * @phpstan-type DynamoOperation array{
 *     operation: string,
 *     table: string|null,
 *     index: string|null,
 *     request: array<array-key, mixed>,
 *     duration_ms: float,
 *     item_count: int|null,
 *     error: string|null,
 *     caller_class: string|null,
 *     caller_method: string|null,
 *     caller_file: string|null,
 *     caller_line: int|null,
 * }
 * @phpstan-type SerializerOperation array{
 *     operation: string,
 *     entity_name: string,
 *     entity_class: string,
 *     duration_ms: float,
 *     caller_class: class-string|null,
 *     caller_method: string|null,
 * }
 *
 * @internal
 *
 * @codeCoverageIgnore
 */
class DynamoDbDataCollector extends AbstractDataCollector
{
    private const string TARGET_HEADER = 'x-amz-target';

    /**
     * @var list<SerializerOperation>
     */
    private array $serializerOperations = [];

    public function __construct(
        private readonly ?TraceableHttpClient $httpClient = null,
    ) {
    }

    /**
     * @param SerializerOperation $operation
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
     * @return list<DynamoOperation>
     */
    private function extractDynamoOperations(): array
    {
        if ($this->httpClient === null) {
            return [];
        }

        $operations = [];
        foreach ($this->httpClient->getTracedRequests() as $trace) {
            $options = self::arrayOrEmpty($trace['options'] ?? null);
            $headers = $options['headers'] ?? [];
            $target = is_iterable($headers) ? $this->extractAmzTarget($headers) : null;
            if ($target === null || !str_starts_with($target, 'DynamoDB_')) {
                continue;
            }

            $operation = explode('.', $target, 2)[1] ?? $target;
            $request = $this->decodeJson($options['body'] ?? null) ?? [];
            $content = self::arrayOrEmpty($trace['content'] ?? null);
            $info = self::arrayOrEmpty($trace['info'] ?? null);
            $caller = self::arrayOrEmpty(self::arrayOrEmpty($options['extra'] ?? null)['dynamo_caller'] ?? null);
            $totalTime = $info['total_time'] ?? null;
            $callerLine = $caller['line'] ?? null;

            $operations[] = [
                'operation' => $operation,
                'table' => self::stringOrNull($request['TableName'] ?? null),
                'index' => self::stringOrNull($request['IndexName'] ?? null),
                'request' => $request,
                'duration_ms' => is_numeric($totalTime) ? (float) $totalTime * 1000 : 0.0,
                'item_count' => $this->extractItemCount($operation, $content),
                'error' => $this->extractError($info, $content),
                'caller_class' => self::stringOrNull($caller['class'] ?? null),
                'caller_method' => self::stringOrNull($caller['method'] ?? null),
                'caller_file' => self::stringOrNull($caller['file'] ?? null),
                'caller_line' => \is_int($callerLine) ? $callerLine : null,
            ];
        }

        return $operations;
    }

    /**
     * @param iterable<mixed, mixed> $headers
     */
    private function extractAmzTarget(iterable $headers): ?string
    {
        foreach ($headers as $key => $value) {
            // Symfony takes headers both as a `name => value` map and as raw `'name: value'` lines.
            $line = \is_string($key) ? $key . ': ' . self::headerValue($value) : self::headerValue($value);
            if (stripos($line, self::TARGET_HEADER . ':') !== 0) {
                continue;
            }

            return trim(substr($line, \strlen(self::TARGET_HEADER) + 1));
        }

        return null;
    }

    /**
     * A header's value as one string; a name may carry a list of values, of which the first is ours.
     */
    private static function headerValue(mixed $value): string
    {
        if (\is_array($value)) {
            $value = $value[0] ?? null;
        }

        return \is_scalar($value) ? (string) $value : '';
    }

    /**
     * @return array<array-key, mixed>|null
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
     * @param array<array-key, mixed> $content
     */
    private function extractItemCount(string $operation, array $content): ?int
    {
        if ($operation === 'Query' || $operation === 'Scan') {
            $count = $content['Count'] ?? null;

            return is_numeric($count) ? (int) $count : null;
        }

        if ($operation === 'GetItem') {
            return empty($content['Item']) ? 0 : 1;
        }

        return null;
    }

    /**
     * @param array<array-key, mixed> $info
     * @param array<array-key, mixed> $content
     */
    private function extractError(array $info, array $content): ?string
    {
        $code = $info['http_code'] ?? null;
        $httpCode = is_numeric($code) ? (int) $code : 0;
        if ($httpCode < 400) {
            return null;
        }

        $type = self::stringOrNull($content['__type'] ?? null);
        $message = self::stringOrNull($content['message'] ?? null) ?? self::stringOrNull($content['Message'] ?? null);
        if ($type === null && $message === null) {
            return \sprintf('HTTP %d', $httpCode);
        }

        return trim(($type !== null ? $type . ': ' : '') . ($message ?? ''));
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function arrayOrEmpty(mixed $value): array
    {
        return \is_array($value) ? $value : [];
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return \is_string($value) ? $value : null;
    }
}
