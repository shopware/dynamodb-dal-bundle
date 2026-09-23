<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Profiler;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * Decorates the client AsyncAws reaches DynamoDB through, outside the TraceableHttpClient layer, to
 * stamp requests with the call site that entered the DAL. The traced options carry this through to
 * {@see DynamoDbDataCollector}, which can't otherwise recover the call stack at late-collect time.
 *
 * Symfony decorator priorities are ordered low-priority-outer / high-priority-inner. HttpClientPass
 * decorates every `http_client.client` with a TraceableHttpClient at priority 100, so 0 here keeps us
 * outside it and the stamped options land in the trace.
 *
 * @internal
 *
 * @codeCoverageIgnore
 */
final class CallerStampingHttpClient implements HttpClientInterface
{
    private const string DAL_NAMESPACE = 'Shopware\\DynamodbDalBundle\\';
    private const string TARGET_HEADER = 'x-amz-target';

    public function __construct(
        private HttpClientInterface $inner,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $headers = $options['headers'] ?? [];
        if (is_iterable($headers) && $this->isDynamoDbRequest($headers)) {
            $caller = $this->findCaller();
            if ($caller !== null) {
                $extra = \is_array($options['extra'] ?? null) ? $options['extra'] : [];
                $extra['dynamo_caller'] = $caller;
                $options['extra'] = $extra;
            }
        }

        return $this->inner->request($method, $url, $options);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->inner->stream($responses, $timeout);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function withOptions(array $options): static
    {
        $clone = clone $this;
        $clone->inner = $this->inner->withOptions($options);

        return $clone;
    }

    /**
     * @param iterable<mixed, mixed> $headers
     */
    private function isDynamoDbRequest(iterable $headers): bool
    {
        foreach ($headers as $key => $value) {
            // Symfony takes headers both as a `name => value` map and as raw `'name: value'` lines.
            $line = \is_string($key) ? $key . ': ' . self::headerValue($value) : self::headerValue($value);
            if (stripos($line, self::TARGET_HEADER . ':') !== 0) {
                continue;
            }

            return str_starts_with(trim(substr($line, \strlen(self::TARGET_HEADER) + 1)), 'DynamoDB_');
        }

        return false;
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
     * The application frame that entered the DAL: the frame right outside the innermost run of
     * DAL frames. Keying on the namespace rather than on a base class keeps this working for any
     * shape of caller — a repository, a service, a controller.
     *
     * @return array{class: class-string, method: string, file: ?string, line: ?int}|null
     */
    private function findCaller(): ?array
    {
        $trace = debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS, 30);

        $lastDalFrame = null;
        foreach ($trace as $index => $frame) {
            if (str_starts_with($frame['class'] ?? '', self::DAL_NAMESPACE)) {
                $lastDalFrame = $index;
            }
        }

        if ($lastDalFrame === null) {
            return null;
        }

        $frame = $trace[$lastDalFrame + 1] ?? null;
        $class = $frame['class'] ?? null;
        if ($frame === null || $class === null) {
            return null;
        }

        return [
            'class' => $class,
            'method' => $frame['function'],
            'file' => $frame['file'] ?? null,
            'line' => $frame['line'] ?? null,
        ];
    }
}
