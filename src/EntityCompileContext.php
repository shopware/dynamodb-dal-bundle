<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle;

use Shopware\DynamodbDalBundle\Serializer\Field\AbstractFieldSerializer;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * @internal Holds entity-level data during definition compilation to keep method signatures short.
 */
final readonly class EntityCompileContext
{
    /**
     * @param array<string, array<string, mixed>> $fieldSerializers Service id => tag attributes from findTaggedServiceIds(AbstractFieldSerializer::class), prefiltered to exclude AbstractFieldSerializer itself
     */
    public function __construct(
        public ContainerBuilder $container,
        public string $itemName,
        public string $entityClass,
        public array $fieldSerializers,
    ) {
    }

    /**
     * @return string|false Service id of the serializer that supports the given type, or false if none
     */
    public function findFieldSerializer(string $phpType, ?string $docblockType): string|false
    {
        return array_find_key(
            $this->fieldSerializers,
            static fn (array $tags, string $serviceId): bool => is_subclass_of($serviceId, AbstractFieldSerializer::class, true)
                && $serviceId::supports($phpType, $docblockType),
        ) ?? false;
    }
}
