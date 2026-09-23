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

    public function __construct(
        private HttpClientInterface $inner,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        if ($this->isDynamoDbRequest($options['headers'] ?? [])) {
            $caller = $this->findCaller();
            if ($caller !== null) {
                $options['extra']['dynamo_caller'] = $caller;
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
     * @param iterable<int|string, mixed> $headers
     */
    private function isDynamoDbRequest(iterable $headers): bool
    {
        foreach ($headers as $key => $value) {
            $name = \is_int($key) ? strtolower(strtok((string) $value, ':') ?: '') : strtolower((string) $key);
            if ($name !== 'x-amz-target') {
                continue;
            }

            $headerPrefixLength = \strlen('x-amz-target:');
            $headerValue = \is_int($key) ? trim(substr((string) $value, $headerPrefixLength)) : (\is_array($value) ? ($value[0] ?? '') : (string) $value);

            return str_starts_with((string) $headerValue, 'DynamoDB_');
        }

        return false;
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
