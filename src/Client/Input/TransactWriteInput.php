<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Client\Input;

use Shopware\DynamodbDalBundle\AbstractEntity;

/**
 * @template Entity of AbstractEntity = never
 */
final class TransactWriteInput
{
    /**
     * @param array<class-string<Entity>, array<DeleteInput<AbstractEntity>|UpdateInput<Entity>|PutInput<Entity>>> $operations - Table mapped to operations
     */
    public function __construct(
        public readonly array $operations = [],
    ) {
    }

    /**
     * @template AddedEntity of AbstractEntity
     *
     * @param class-string<AddedEntity> $class
     * @param PutInput<AddedEntity>|DeleteInput<AbstractEntity>|UpdateInput<AddedEntity> $input
     *
     * @return self<Entity|AddedEntity>
     */
    public function with(string $class, PutInput|DeleteInput|UpdateInput $input): self
    {
        return new self([
            ...$this->operations,
            $class => [...($this->operations[$class] ?? []), $input],
        ]);
    }
}
