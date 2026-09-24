<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Client\Input;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Client\Index;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * A read request for items spanning one or multiple tables by {@see Index}, keyed by the entity class each set of
 * keys addresses — the reader resolves the {@see \Shopware\DynamodbDalBundle\Definition\EntityDefinition} (and with
 * it the physical table) from that class.
 *
 * @template Entity of AbstractEntity = never
 */
#[Exclude]
class GetInput
{
    /**
     * @var array<class-string<Entity>, list<Index>>
     */
    public readonly array $keysByClass;

    /**
     * @param array<class-string<Entity>, list<Index>> $keysByClass
     */
    public function __construct(
        array $keysByClass,
        public readonly ?bool $consistentRead = null,
    ) {
        $this->keysByClass = array_filter($keysByClass, static fn (array $keys): bool => $keys !== []);
    }

    /**
     * The same request with `$keys` added to `$class`'s bucket, as a new instance — widening the entity
     * union so the resulting {@see \Shopware\DynamodbDalBundle\Client\Output\GetOutput} stays typed.
     *
     * @template AddedEntity of AbstractEntity
     *
     * @param class-string<AddedEntity> $class
     *
     * @return self<Entity|AddedEntity>
     */
    public function withKey(string $class, Index ...$keys): self
    {
        $keysByClass = $this->keysByClass;
        foreach ($keys as $key) {
            $keysByClass[$class][] = $key;
        }

        return new self($keysByClass, $this->consistentRead);
    }

    /**
     * @param ?bool $consistentRead - `ConsistentRead` of the `GetItem`/`BatchGetItem`. Strongly consistent read; off by default (eventually consistent reads are cheaper)
     *
     * @return self<Entity>
     */
    public function withConsistentRead(?bool $consistentRead): self
    {
        return new self($this->keysByClass, $consistentRead);
    }
}
