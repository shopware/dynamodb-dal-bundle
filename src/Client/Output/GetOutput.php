<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Client\Output;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * The typed result of a single- or multi-table {@see GetInput} — a bounded key read,
 * cached on first access (multi-key order is not preserved).
 *
 * @template Entity of AbstractEntity = never
 *
 * @implements \IteratorAggregate<int, Entity>
 */
#[Exclude]
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
     * @return \Traversable<int, Entity>
     */
    public function getIterator(): \Traversable
    {
        yield from $this->load();
    }

    /**
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
     * @return ?Entity
     */
    public function first(): ?AbstractEntity
    {
        return $this->load()[0] ?? null;
    }

    /**
     * @return list<Entity>
     */
    private function load(): array
    {
        return $this->cache ??= iterator_to_array($this->source, false);
    }
}
