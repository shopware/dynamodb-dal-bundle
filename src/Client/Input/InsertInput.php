<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Client\Input;

use Shopware\DynamodbDalBundle\AbstractEntity;

/**
 * Writes an entity as a new item, where none is stored under its key.
 * Unlike a {@see PutInput}, it never replaces a stored item.
 *
 * @template-covariant Entity of AbstractEntity
 */
final readonly class InsertInput
{
    /**
     * @var class-string<Entity>
     */
    public string $class;

    /**
     * @param Entity $entity - the whole item to write
     */
    public function __construct(
        public AbstractEntity $entity,
    ) {
        $this->class = $entity::class;
    }
}
