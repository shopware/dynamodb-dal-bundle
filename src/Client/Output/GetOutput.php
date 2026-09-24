<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Client\Output;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Exception\DALException;
use Shopware\DynamodbDalBundle\Exception\UnknownEntityDefinitionException;
use AsyncAws\Core\Exception\Exception as AsyncAwsException;

/**
 * The typed result of a single- or multi-table {@see GetInput} — a bounded key read,
 * cached on first access (multi-key order is not preserved).
 *
 * @template Entity of AbstractEntity = never
 *
 * @implements \IteratorAggregate<int, Entity>
 */
final class GetOutput implements \IteratorAggregate
{
    /**
     * @var ?list<Entity>
     */
    private ?array $cache = null;

    /**
     * @internal
     *
     * @param \Generator<int, Entity> $source - each found entity
     */
    public function __construct(
        private readonly \Generator $source,
    ) {
    }

    /**
     * @throws UnknownEntityDefinitionException
     * @throws DALException if a key does not serialize, or a stored item does not deserialize
     * @throws AsyncAwsException if a request to DynamoDB fails
     *
     * @return \Traversable<int, Entity>
     */
    public function getIterator(): \Traversable
    {
        yield from $this->load();
    }

    /**
     * @throws UnknownEntityDefinitionException
     * @throws DALException if a key does not serialize, or a stored item does not deserialize
     * @throws AsyncAwsException if a request to DynamoDB fails
     *
     * @return list<Entity>
     */
    public function toArray(): array
    {
        return $this->load();
    }

    /**
     * The found entities of one entity class.
     *
     * @template E of Entity
     *
     * @param class-string<E> $class
     *
     * @throws UnknownEntityDefinitionException
     * @throws DALException if a key does not serialize, or a stored item does not deserialize
     * @throws AsyncAwsException if a request to DynamoDB fails
     *
     * @return list<E>
     */
    public function forEntity(string $class): array
    {
        $entities = [];
        foreach ($this->load() as $entity) {
            if ($entity instanceof $class) {
                $entities[] = $entity;
            }
        }

        return $entities;
    }

    /**
     * All found entities bucketed by their exact entity class.
     *
     * @throws UnknownEntityDefinitionException
     * @throws DALException if a key does not serialize, or a stored item does not deserialize
     * @throws AsyncAwsException if a request to DynamoDB fails
     *
     * @return array<class-string<Entity>, non-empty-list<Entity>>
     */
    public function grouped(): array
    {
        $grouped = [];
        foreach ($this->load() as $entity) {
            $grouped[$entity::class][] = $entity;
        }

        return $grouped;
    }

    /**
     * @throws UnknownEntityDefinitionException
     * @throws DALException if a key does not serialize, or a stored item does not deserialize
     * @throws AsyncAwsException if a request to DynamoDB fails
     *
     * @return ?Entity
     */
    public function first(): ?AbstractEntity
    {
        return $this->load()[0] ?? null;
    }

    /**
     * @throws UnknownEntityDefinitionException
     * @throws DALException if a key does not serialize, or a stored item does not deserialize
     * @throws AsyncAwsException if a request to DynamoDB fails
     *
     * @return list<Entity>
     *
     * @phpstan-ignore-next-line throws.unusedType -- the source generator throws as iterator_to_array() reads it
     */
    private function load(): array
    {
        return $this->cache ??= iterator_to_array($this->source, false);
    }
}
