<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Client\Input;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Client\Key;

/**
 * A read request for items by {@see Key}, spanning one or multiple tables.
 *
 * @template-covariant Entity of AbstractEntity = never
 */
final readonly class GetInput
{
    /**
     * @var list<Key<Entity>>
     */
    public array $keys;

    /**
     * @param list<Key<Entity>> $keys
     * @param bool $consistentRead - `ConsistentRead` of the `GetItem`/`BatchGetItem`; off by default (eventually consistent reads are cheaper)
     */
    public function __construct(
        array $keys = [],
        public bool $consistentRead = false,
    ) {
        $this->keys = array_values($keys);
    }

    /**
     * @template AddedEntity of AbstractEntity
     *
     * @param Key<AddedEntity> ...$keys
     *
     * @return self<Entity|AddedEntity>
     */
    public function withKey(Key ...$keys): self
    {
        return new self([...$this->keys, ...array_values($keys)], $this->consistentRead);
    }

    /**
     * @return self<Entity>
     */
    public function withConsistentRead(bool $consistentRead = true): self
    {
        return new self($this->keys, $consistentRead);
    }
}
