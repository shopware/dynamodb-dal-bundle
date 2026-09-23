<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Definition;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Serializer\AbstractNormalizer;

/**
 * @template-covariant Entity of AbstractEntity = AbstractEntity
 */
class EntityDefinition
{
    /**
     * @var array<string, FieldDefinition<Entity>>
     */
    private readonly array $fieldDefinitions;

    /**
     * @internal
     *
     * @param class-string<Entity> $class
     * @param iterable<string, FieldDefinition<Entity>> $fieldDefinitions
     * @param array<string, IndexSchema> $indexes - keyed by index name
     */
    public function __construct(
        private readonly string $name,
        private readonly string $table,
        private readonly string $class,
        private readonly ?AbstractNormalizer $normalizer,
        iterable $fieldDefinitions,
        private readonly KeySchema $keySchema,
        private readonly array $indexes = [],
    ) {
        $definitions = [];
        foreach ($fieldDefinitions as $key => $fieldDefinition) {
            // backreference to the entity definition
            $fieldDefinition->setEntityDefinition($this);
            $definitions[$key] = $fieldDefinition;
        }

        $this->fieldDefinitions = $definitions;
    }

    /**
     * Key schema of the base table.
     */
    public function getKeySchema(): KeySchema
    {
        return $this->keySchema;
    }

    /**
     * Key schema of the named global secondary index, or null if it is not declared.
     */
    public function getIndex(string $name): ?IndexSchema
    {
        return $this->indexes[$name] ?? null;
    }

    /**
     * @return array<string, IndexSchema> - keyed by index name
     */
    public function getIndexes(): array
    {
        return $this->indexes;
    }

    /**
     * Public name of the item, as defined in the `#[Table(name: ..)]` attribute
     */
    public function getName(): string
    {
        return $this->name;
    }

    public function getTable(): string
    {
        return $this->table;
    }

    /**
     * @return class-string<Entity> $class
     */
    public function getClass(): string
    {
        return $this->class;
    }

    /**
     * Normalizer used for the item, as defined in the `#[Table(normalizer: ..)]` attribute
     */
    public function getNormalizer(): ?AbstractNormalizer
    {
        return $this->normalizer;
    }

    /**
     * @return array<string, FieldDefinition<Entity>>
     */
    public function getFieldDefinitions(): array
    {
        return $this->fieldDefinitions;
    }

    /**
     * @return FieldDefinition<Entity>|null
     */
    public function getFieldDefinition(string $propertyName): ?FieldDefinition
    {
        return $this->fieldDefinitions[$propertyName] ?? null;
    }

    /**
     * @return list<string>
     */
    public function getFieldNames(): array
    {
        return array_keys($this->fieldDefinitions);
    }

    /**
     * @return Entity
     */
    public function createInstance(): AbstractEntity
    {
        return new ($this->class)();
    }
}
