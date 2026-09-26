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
 * @internal
 *
 * @codeCoverageIgnore
 */
final class CallerStampingHttpClient implements HttpClientInterface
{
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
        if (is_iterable($headers) && str_starts_with(DynamoDbCall::target($headers) ?? '', 'DynamoDB_')) {
            $caller = DynamoDbCall::caller();
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
}
