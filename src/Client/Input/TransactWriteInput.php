<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Client\Input;

use Shopware\DynamodbDalBundle\AbstractEntity;

/**
 * Puts, updates and deletes across entity classes, written as one all-or-nothing `TransactWriteItems`. The operations
 * are sent in the order they are given, which is the order of the cancellation reasons a failed transaction reports.
 */
final readonly class TransactWriteInput
{
    /**
     * @var list<PutInput<AbstractEntity>|UpdateInput<AbstractEntity>|DeleteInput<AbstractEntity>>
     */
    public array $operations;

    /**
     * @param PutInput<AbstractEntity>|UpdateInput<AbstractEntity>|DeleteInput<AbstractEntity> ...$operations
     */
    public function __construct(PutInput|UpdateInput|DeleteInput ...$operations)
    {
        $this->operations = array_values($operations);
    }

    /**
     * @param PutInput<AbstractEntity>|UpdateInput<AbstractEntity>|DeleteInput<AbstractEntity> ...$operations
     */
    public function with(PutInput|UpdateInput|DeleteInput ...$operations): self
    {
        return new self(...$this->operations, ...array_values($operations));
    }
}
