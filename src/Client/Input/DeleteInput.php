<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Client\Input;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Client\Key;
use Shopware\DynamodbDalBundle\Expression\Contract\FilterInterface;

/**
 * @template-covariant Entity of AbstractEntity
 */
final readonly class DeleteInput
{
    /**
     * @var class-string<Entity>
     */
    public string $class;

    /**
     * @param Entity|Key<Entity> $key
     * @param ?FilterInterface $condition - checked against the stored item; the delete is refused if it does not hold
     */
    public function __construct(
        public AbstractEntity|Key $key,
        public ?FilterInterface $condition = null,
    ) {
        $this->class = $key instanceof Key ? $key->class : $key::class;
    }
}
