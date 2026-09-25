<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\Entity;

use Shopware\DynamodbDalBundle\Serializer\AbstractNormalizer;
use Shopware\DynamodbDalBundle\Serializer\NormalizerContext;
use Shopware\DynamodbDalBundle\Serializer\NormalizerOperation;
use Symfony\Component\Uid\Uuid;

class NormalizedEntityNormalizer extends AbstractNormalizer
{
    public const string DEFAULT_LABEL = 'unlabelled';

    /**
     * Fixed, like the generated `createdAt`, so a test can tell the stamp from any other time.
     */
    public const int UPDATED_AT = 1_700_000_500;

    public function normalize(NormalizerContext $context): void
    {
        // Any write that names the label without a value stores the default rather than dropping a required field.
        $context->setIfUnset('label', self::DEFAULT_LABEL);

        // Only an update is known to change a stored row; a put may be its first write.
        if ($context->operation === NormalizerOperation::Update) {
            $context->set('updatedAt', new \DateTimeImmutable('@' . self::UPDATED_AT));

            return;
        }

        if ($context->operation !== NormalizerOperation::Put) {
            return;
        }

        $context->setIfUnset('id', Uuid::v7());
        $context->setIfUnset('createdAt', new \DateTimeImmutable('@1700000000'));

        // The partition key exists only in the stored row; it is composed from the two fields the
        // call site does set.
        $tenantId = $context->get('tenantId') ?? '';
        $kind = $context->get('kind') ?? '';
        if ($context->get('pk') === null && \is_string($tenantId) && \is_string($kind)) {
            $context->set('pk', \sprintf('%s#%s', $tenantId, $kind));
        }
    }

    public function denormalize(NormalizerContext $context): void
    {
        // A row written before `label` existed carries no value for it; fill it in rather than
        // failing the read on a missing required field.
        $context->setIfUnset('label', self::DEFAULT_LABEL);
    }
}
