<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Client\Input;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Expression\Contract\FilterInterface;

/**
 * @template-covariant Entity of AbstractEntity
 */
final readonly class PutInput
{
    /**
     * @var class-string<Entity>
     */
    public string $class;

    /**
     * @param Entity $entity - the whole item to write, replacing a stored one with the same key
     * @param ?FilterInterface $condition - checked against the stored item; the write is refused if it does not hold
     */
    public function __construct(
        public AbstractEntity $entity,
        public ?FilterInterface $condition = null,
    ) {
        $this->class = $entity::class;
    }
}
