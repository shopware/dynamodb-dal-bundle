<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Client\Write;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Serializer\NormalizerOperation;

/**
 * How an entity is brought up to date once its write is stored, where the write returns no item: it takes the fields
 * the write sent, or its item is read back where those cannot say what was stored.
 *
 * @internal
 */
final readonly class WriteBack
{
    /**
     * @param ?array<string, mixed> $fields - normalized for `$operation`, as they were written; `null` reads the item back
     */
    private function __construct(
        public AbstractEntity $entity,
        public EntityDefinition $definition,
        public ?array $fields,
        public NormalizerOperation $operation,
    ) {
    }

    /**
     * @param array<string, mixed> $fields - normalized for `$operation`, as they were written; a path into an attribute is skipped
     */
    public static function fields(AbstractEntity $entity, EntityDefinition $definition, array $fields, NormalizerOperation $operation): self
    {
        return new self($entity, $definition, $fields, $operation);
    }

    /**
     * For a write whose fields cannot say what was stored: a nested path or an action, whose value DynamoDB computes.
     */
    public static function readBack(AbstractEntity $entity, EntityDefinition $definition): self
    {
        return new self($entity, $definition, null, NormalizerOperation::Read);
    }
}
