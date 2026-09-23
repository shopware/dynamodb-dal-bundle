<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Definition;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Exception\UnknownEntityDefinitionException;

/**
 * Lookup of every {@see EntityDefinition}, by its logical table name (the `#[Table(name: ..)]` value,
 * e.g. `order`) via {@see get()} or by its physical DynamoDB table name via {@see getByTableName()}.
 * Populated from the `dal.definition` tagged services the
 * {@see \Shopware\DynamodbDalBundle\DefinitionCompilerPass} registers.
 */
class EntityDefinitionRegistry
{
    /**
     * @var array<string, EntityDefinition<AbstractEntity>> - keyed by logical table name
     */
    private readonly array $definitions;

    /**
     * @var array<string, string> - physical DynamoDB table name => logical table name
     */
    private array $logicalByPhysical = [];

    /**
     * @var array<class-string<AbstractEntity>, string> - entity class => logical table name
     */
    private array $logicalByEntityClass = [];

    /**
     * @internal
     *
     * @param iterable<string, EntityDefinition<AbstractEntity>> $definitions - keyed by logical table name
     */
    public function __construct(iterable $definitions)
    {
        $this->definitions = iterator_to_array($definitions);

        foreach ($this->definitions as $logicalName => $definition) {
            $this->logicalByPhysical[$definition->getTable()] = $logicalName;
            $this->logicalByEntityClass[$definition->getClass()] = $logicalName;
        }
    }

    /**
     * Resolves a definition by its logical table name (the `#[Table(name: ..)]` value).
     *
     * @throws UnknownEntityDefinitionException when no definition is registered for the given logical name
     *
     * @return EntityDefinition<AbstractEntity>
     */
    public function get(string $name): EntityDefinition
    {
        return $this->definitions[$name] ?? throw new UnknownEntityDefinitionException($name);
    }

    /**
     * Resolves a definition by its physical DynamoDB table name — the key DynamoDB uses in a
     * `BatchGetItem` response, which a caller needs to map back to an entity for deserialization.
     *
     * @throws UnknownEntityDefinitionException when no definition is registered for the given physical table
     *
     * @return EntityDefinition<AbstractEntity>
     */
    public function getByTableName(string $table): EntityDefinition
    {
        return $this->get($this->logicalByPhysical[$table] ?? throw new UnknownEntityDefinitionException($table));
    }

    /**
     * @template Entity of AbstractEntity
     *
     * @param class-string<Entity> $class
     *
     * @throws UnknownEntityDefinitionException when no definition is registered for the given entity class
     *
     * @return EntityDefinition<Entity>
     */
    public function getByEntityClass(string $class): EntityDefinition
    {
        $entityDefinition = $this->get($this->logicalByEntityClass[$class] ?? throw new UnknownEntityDefinitionException($class));
        /** @var EntityDefinition<Entity> $entityDefinition */

        return $entityDefinition;
    }

    public function has(string $name): bool
    {
        return isset($this->definitions[$name]);
    }
}
