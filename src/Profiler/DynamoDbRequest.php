<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Profiler;

/**
 * A DynamoDB request as the profiler shows it, read off a request Symfony's TraceableHttpClient traced.
 *
 * A traced request is untyped all the way down — headers, body and response content are whatever the wire carried —
 * so every value the panel shows is narrowed out of it here, once.
 *
 * @internal
 */
final readonly class DynamoDbRequest
{
    /**
     * The `extra` option {@see CallStampingHttpClient} stamps a DynamoDB request with.
     */
    public const string STAMP = 'dynamodb_dal_stamp';

    private const string TARGET_HEADER = 'x-amz-target';

    private const string TARGET_PREFIX = 'DynamoDB_';

    /**
     * @param ?string $table - the tables of a batch or transaction joined by commas
     * @param array<array-key, mixed> $payload - the request body, decoded
     * @param ?int $items - how many items the response returned, where it returns items
     * @param ?int $counted - how many items a `Select=COUNT` query or scan counted, as it returns none
     */
    private function __construct(
        public string $operation,
        public ?string $table,
        public ?string $index,
        public array $payload,
        public ?int $items,
        public ?int $counted,
        public ?string $error,
    ) {
    }

    /**
     * @param array<array-key, mixed> $trace - one of {@see \Symfony\Component\HttpClient\TraceableHttpClient::getTracedRequests()}
     */
    public static function fromTrace(array $trace): self
    {
        $options = self::arrayOrEmpty($trace['options'] ?? null);
        $headers = $options['headers'] ?? [];
        $target = is_iterable($headers) ? self::target($headers) : null;
        $payload = self::decode($options['body'] ?? null) ?? [];

        // An array where AsyncAws read the body with toArray(), a string where it read an error body with getContent()
        $content = self::decode($trace['content'] ?? null);

        $info = self::arrayOrEmpty($trace['info'] ?? null);
        $code = $info['http_code'] ?? null;
        $httpCode = is_numeric($code) ? (int) $code : 0;

        $operation = explode('.', $target ?? '', 2)[1] ?? $target ?? 'unknown';
        $error = $httpCode >= 400 ? self::error($httpCode, $content ?? []) : null;
        $counts = ($payload['Select'] ?? null) === 'COUNT';

        return new self(
            $operation,
            self::table($payload),
            self::stringOrNull($payload['IndexName'] ?? null),
            $payload,
            $error === null && $content !== null && !$counts ? self::items($operation, $content) : null,
            $error === null && $content !== null && $counts ? self::intOrNull($content['Count'] ?? null) : null,
            $error,
        );
    }

    /**
     * @param iterable<mixed, mixed> $headers
     */
    public static function isDynamoDb(iterable $headers): bool
    {
        return str_starts_with(self::target($headers) ?? '', self::TARGET_PREFIX);
    }

    /**
     * The stamp {@see CallStampingHttpClient} gave a traced request, or `null` for a request that is not DynamoDB's.
     *
     * @param array<array-key, mixed> $trace
     */
    public static function stamp(array $trace): ?int
    {
        $options = self::arrayOrEmpty($trace['options'] ?? null);
        $stamp = self::arrayOrEmpty($options['extra'] ?? null)[self::STAMP] ?? null;

        return \is_int($stamp) ? $stamp : null;
    }

    /**
     * The request's `x-amz-target` header, e.g. `DynamoDB_20120810.Query`, or `null` without one.
     *
     * @param iterable<mixed, mixed> $headers
     */
    private static function target(iterable $headers): ?string
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
     * The table a request names, or the tables of a batch or transaction, which name them per request
     *
     * @param array<array-key, mixed> $payload
     */
    private static function table(array $payload): ?string
    {
        $tables = \is_string($payload['TableName'] ?? null) ? [$payload['TableName']] : [];

        // BatchGetItem and BatchWriteItem key their requests by table
        foreach (array_keys(self::arrayOrEmpty($payload['RequestItems'] ?? null)) as $table) {
            $tables[] = (string) $table;
        }

        // TransactGetItems and TransactWriteItems name a table in every item, under what the item does
        foreach (self::arrayOrEmpty($payload['TransactItems'] ?? null) as $item) {
            foreach (self::arrayOrEmpty($item) as $operation) {
                $table = self::arrayOrEmpty($operation)['TableName'] ?? null;
                if (\is_string($table)) {
                    $tables[] = $table;
                }
            }
        }

        return $tables !== [] ? implode(', ', array_unique($tables)) : null;
    }

    /**
     * @param array<array-key, mixed> $content
     */
    private static function items(string $operation, array $content): ?int
    {
        return match ($operation) {
            'Query', 'Scan' => self::intOrNull($content['Count'] ?? null),
            // A key that matches no item gets a response without `Item`
            'GetItem' => !empty($content['Item']) ? 1 : 0,
            'BatchGetItem' => array_sum(array_map(
                static fn (mixed $items): int => \is_array($items) ? \count($items) : 0,
                self::arrayOrEmpty($content['Responses'] ?? null),
            )),
            default => null,
        };
    }

    /**
     * @param array<array-key, mixed> $content
     */
    private static function error(int $httpCode, array $content): string
    {
        // `__type` puts the service before the error: `com.amazonaws.dynamodb.v20120810#ValidationException`
        $type = self::stringOrNull($content['__type'] ?? null);
        $code = $type !== null ? (explode('#', $type, 2)[1] ?? $type) : null;
        $message = self::stringOrNull($content['message'] ?? null) ?? self::stringOrNull($content['Message'] ?? null);

        return match (true) {
            $code !== null && $message !== null => $code . ': ' . $message,
            $code !== null => $code,
            $message !== null => $message,
            default => \sprintf('HTTP %d', $httpCode),
        };
    }

    /**
     * @return ?array<array-key, mixed>
     */
    private static function decode(mixed $value): ?array
    {
        if (\is_array($value)) {
            return $value;
        }

        if (!\is_string($value) || $value === '') {
            return null;
        }

        try {
            $decoded = json_decode($value, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($decoded) ? $decoded : null;
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

    private static function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
