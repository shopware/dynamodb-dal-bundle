<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Profiler;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * Decorates the client AsyncAws reaches DynamoDB through, outside the TraceableHttpClient layer, to stamp every
 * DynamoDB request. The traced options carry the stamp, by which {@see DalCallTracer} files the request under the
 * DAL call that sent it.
 *
 * @internal
 */
final class CallStampingHttpClient implements HttpClientInterface
{
    public function __construct(
        private HttpClientInterface $inner,
        private readonly DalCallTracer $tracer,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $headers = $options['headers'] ?? [];
        $stamp = is_iterable($headers) && DynamoDbRequest::isDynamoDb($headers) ? $this->tracer->stamp() : null;

        if ($stamp !== null) {
            $extra = \is_array($options['extra'] ?? null) ? $options['extra'] : [];
            $extra[DynamoDbRequest::STAMP] = $stamp;
            $options['extra'] = $extra;
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
