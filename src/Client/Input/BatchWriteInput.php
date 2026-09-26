<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Client\Input;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Client\Key;

/**
 * Puts and deletes across entity classes, written through `BatchWriteItem`, 25 per request. A batch is not atomic and
 * cannot be conditional, so it takes entities and keys rather than inputs that carry a condition. For either, write a
 * {@see TransactWriteInput}.
 */
final readonly class BatchWriteInput
{
    /**
     * @var list<AbstractEntity>
     */
    public array $puts;

    /**
     * @var list<AbstractEntity|Key<AbstractEntity>>
     */
    public array $deletes;

    /**
     * @param list<AbstractEntity> $puts - the whole items to write, each replacing a stored one with the same key
     * @param list<AbstractEntity|Key<AbstractEntity>> $deletes - the items to delete, by key or entity; one that does not exist is not an error
     */
    public function __construct(array $puts = [], array $deletes = [])
    {
        $this->puts = array_values($puts);
        $this->deletes = array_values($deletes);
    }

    public function withPut(AbstractEntity ...$entities): self
    {
        return new self([...$this->puts, ...array_values($entities)], $this->deletes);
    }

    /**
     * @param AbstractEntity|Key<AbstractEntity> ...$keys
     */
    public function withDelete(AbstractEntity|Key ...$keys): self
    {
        return new self($this->puts, [...$this->deletes, ...array_values($keys)]);
    }
}
