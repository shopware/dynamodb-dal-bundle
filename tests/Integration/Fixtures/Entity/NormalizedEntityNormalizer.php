<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\Entity;

use Shopware\DynamodbDalBundle\Serializer\AbstractNormalizer;
use Symfony\Component\Uid\Uuid;

/**
 * @extends AbstractNormalizer<array<string, mixed>, array<string, mixed>>
 */
class NormalizedEntityNormalizer extends AbstractNormalizer
{
    public const string DEFAULT_LABEL = 'unlabelled';

    public function normalize(array $fields, array $keys): array
    {
        if (isset($keys['id'])) {
            $fields['id'] ??= Uuid::v7();
        }

        if (isset($keys['createdAt'])) {
            $fields['createdAt'] ??= new \DateTimeImmutable('@1700000000');
        }

        // The partition key exists only in the stored row; it is composed from the two fields the
        // call site does set.
        $tenantId = $fields['tenantId'] ?? '';
        $kind = $fields['kind'] ?? '';
        if (isset($keys['pk']) && ($fields['pk'] ?? null) === null && \is_string($tenantId) && \is_string($kind)) {
            $fields['pk'] = \sprintf('%s#%s', $tenantId, $kind);
        }

        if (isset($keys['label'])) {
            $fields['label'] ??= self::DEFAULT_LABEL;
        }

        return $fields;
    }

    public function denormalize(array $fields, array $keys): array
    {
        // A row written before `label` existed carries no value for it; fill it in rather than
        // failing the read on a missing required field.
        if (isset($keys['label'])) {
            $fields['label'] ??= self::DEFAULT_LABEL;
        }

        return $fields;
    }
}
