<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures;

use Shopware\DynamodbDalBundle\Serializer\AbstractNormalizer;
use Shopware\DynamodbDalBundle\Serializer\NormalizerContext;
use Shopware\DynamodbDalBundle\Serializer\NormalizerOperation;

/**
 * Records every call as it arrives, and lets a test change the fields either side works on.
 */
class RecordingNormalizer extends AbstractNormalizer
{
    /**
     * @var list<array{'normalize'|'denormalize', NormalizerOperation, array<string, mixed>}>
     */
    public array $calls = [];

    /**
     * @param ?\Closure(NormalizerContext): void $normalize
     * @param ?\Closure(NormalizerContext): void $denormalize
     */
    public function __construct(
        private readonly ?\Closure $normalize = null,
        private readonly ?\Closure $denormalize = null,
    ) {
    }

    public function normalize(NormalizerContext $context): void
    {
        $this->calls[] = ['normalize', $context->operation, $context->getFields()];

        if ($this->normalize !== null) {
            ($this->normalize)($context);
        }
    }

    public function denormalize(NormalizerContext $context): void
    {
        $this->calls[] = ['denormalize', $context->operation, $context->getFields()];

        if ($this->denormalize !== null) {
            ($this->denormalize)($context);
        }
    }
}
