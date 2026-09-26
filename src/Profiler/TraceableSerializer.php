<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Profiler;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Serializer\NormalizerOperation;
use Shopware\DynamodbDalBundle\Serializer\SerializedResult;
use Shopware\DynamodbDalBundle\Serializer\Serializer;
use Symfony\Component\Stopwatch\Stopwatch;

/**
 * Decorates the DAL Serializer to capture serialize/deserialize timings for the Symfony profiler.
 * The captured timings are aggregated by the calling method so the panel can show a combined
 * per-call-site total (DynamoDB API time + serializer time).
 *
 * @internal
 *
 * @codeCoverageIgnore
 */
final class TraceableSerializer extends Serializer
{
    public function __construct(
        private readonly Serializer $inner,
        private readonly DynamoDbDataCollector $collector,
        private readonly ?Stopwatch $stopwatch = null,
    ) {
    }

    public function deserialize(EntityDefinition $definition, array $output, ?AbstractEntity $entity = null): ?AbstractEntity
    {
        return $this->trace('deserialize', NormalizerOperation::Read, $definition, fn (): ?AbstractEntity => $this->inner->deserialize($definition, $output, $entity));
    }

    public function deserializeFields(EntityDefinition $definition, array $output, NormalizerOperation $operation): array
    {
        return $this->trace('deserializeFields', $operation, $definition, fn (): array => $this->inner->deserializeFields($definition, $output, $operation));
    }

    public function serialize(EntityDefinition $definition, AbstractEntity|array $fields, NormalizerOperation $operation): SerializedResult
    {
        return $this->trace('serialize', $operation, $definition, fn (): SerializedResult => $this->inner->serialize($definition, $fields, $operation));
    }

    public function normalize(EntityDefinition $definition, array $fields, NormalizerOperation $operation): array
    {
        return $this->trace('normalize', $operation, $definition, fn (): array => $this->inner->normalize($definition, $fields, $operation));
    }

    /**
     * The writer calls this on its own to apply a write's fields back onto the entity.
     */
    public function denormalize(EntityDefinition $definition, array $fields, NormalizerOperation $operation): array
    {
        return $this->trace('denormalize', $operation, $definition, fn (): array => $this->inner->denormalize($definition, $fields, $operation));
    }

    /**
     * @template TReturn
     *
     * @param callable(): TReturn $callback
     *
     * @return TReturn
     */
    private function trace(string $operation, NormalizerOperation $normalizerOperation, EntityDefinition $definition, callable $callback): mixed
    {
        $caller = DynamoDbCall::caller();
        $stopwatchEvent = $this->stopwatch?->start(\sprintf('dynamodb.serializer.%s', $operation), 'dynamodb.serializer');
        $startedAt = microtime(true);

        try {
            return $callback();
        } finally {
            $durationMs = (microtime(true) - $startedAt) * 1000;
            $stopwatchEvent?->stop();

            $this->collector->addSerializerOperation([
                'operation' => $operation,
                'normalizer_operation' => $normalizerOperation->name,
                'entity_name' => $definition->getName(),
                'entity_class' => $definition->getClass(),
                'duration_ms' => $durationMs,
                'caller_class' => $caller['class'] ?? null,
                'caller_method' => $caller['method'] ?? null,
            ]);
        }
    }
}
