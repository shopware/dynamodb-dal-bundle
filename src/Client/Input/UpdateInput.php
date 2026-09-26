<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Client\Input;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Client\Key;
use Shopware\DynamodbDalBundle\Expression\Contract\FilterInterface;
use Shopware\DynamodbDalBundle\Expression\Update;
use Shopware\DynamodbDalBundle\Expression\Update\UpdateExpression;

/**
 * @template-covariant Entity of AbstractEntity
 */
final readonly class UpdateInput
{
    /**
     * @var class-string<Entity>
     */
    public string $class;

    public UpdateExpression $update;

    /**
     * @param Entity|Key<Entity> $key
     * @param array<string, mixed>|UpdateExpression $update - fields keyed by path, set to their value or removed for `null`, or an expression built with {@see Update}
     * @param ?FilterInterface $condition - checked on top of the item existing, which every update requires
     * @param Refresh $refresh - how an entity given as `$key` takes what the update wrote; ignored for a {@see Key}
     */
    public function __construct(
        public AbstractEntity|Key $key,
        array|UpdateExpression $update,
        public ?FilterInterface $condition = null,
        public Refresh $refresh = Refresh::Full,
    ) {
        $this->class = $key instanceof Key ? $key->class : $key::class;
        $this->update = \is_array($update) ? Update::setFields($update) : $update;
    }
}
