<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Client\Input;

use Shopware\DynamodbDalBundle\AbstractEntity;

/**
 * A read-back request for entities spanning one or multiple tables: each entity is re-read by its own key and
 * the stored row is deserialized back into that same instance.
 *
 * @template Entity of AbstractEntity = never
 */
class RefreshInput
{
    /**
     * @param list<Entity> $entities
     */
    public function __construct(
        public readonly array $entities,
        public readonly ?bool $consistentRead = null,
    ) {
    }

    /**
     * The same request with `$entities` added, as a new instance — widening the entity union.
     *
     * @template AddedEntity of AbstractEntity
     *
     * @param AddedEntity ...$entities
     *
     * @return self<Entity|AddedEntity>
     */
    public function withEntity(AbstractEntity ...$entities): self
    {
        return new self([...$this->entities, ...array_values($entities)], $this->consistentRead);
    }

    /**
     * @return self<Entity>
     */
    public function withConsistentRead(?bool $consistentRead): self
    {
        return new self($this->entities, $consistentRead);
    }
}
