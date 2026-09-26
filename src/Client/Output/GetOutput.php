<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Client\Output;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Client\Input\GetInput;
use Shopware\DynamodbDalBundle\Exception\DALException;
use Shopware\DynamodbDalBundle\Exception\UnknownEntityDefinitionException;
use AsyncAws\Core\Exception\Exception as AsyncAwsException;

/**
 * The entities a single- or multi-table {@see GetInput} finds, in no particular order, streamed once like the
 * matches of a search: each `BatchGetItem` of 100 keys is read as the stream reaches it.
 * {@see forEntity()} and {@see grouped()} read it as the other terminals do, so use one of them, and only once.
 *
 * @template Entity of AbstractEntity = never
 *
 * @extends ReadOutput<Entity, int>
 *
 * @final - considered final, but not marked as such so a test can double it
 */
class GetOutput extends ReadOutput
{
    /**
     * The found entities of one entity class.
     *
     * @template E of Entity
     *
     * @param class-string<E> $class
     *
     * @throws \LogicException if this output has already been read
     * @throws UnknownEntityDefinitionException
     * @throws DALException if a key does not serialize, or a stored item does not deserialize
     * @throws AsyncAwsException if a request to DynamoDB fails
     *
     * @return list<E>
     */
    public function forEntity(string $class): array
    {
        $entities = [];
        foreach ($this->stream() as $entity) {
            if ($entity instanceof $class) {
                $entities[] = $entity;
            }
        }

        return $entities;
    }

    /**
     * All found entities bucketed by their exact entity class.
     *
     * @throws \LogicException if this output has already been read
     * @throws UnknownEntityDefinitionException
     * @throws DALException if a key does not serialize, or a stored item does not deserialize
     * @throws AsyncAwsException if a request to DynamoDB fails
     *
     * @return array<class-string<Entity>, non-empty-list<Entity>>
     */
    public function grouped(): array
    {
        $grouped = [];
        foreach ($this->stream() as $entity) {
            $grouped[$entity::class][] = $entity;
        }

        return $grouped;
    }
}
