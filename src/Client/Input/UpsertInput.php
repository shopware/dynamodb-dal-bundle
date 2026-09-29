<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Client\Input;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Expression\Contract\FilterInterface;
use Shopware\DynamodbDalBundle\Expression\Update;
use Shopware\DynamodbDalBundle\Expression\Update\UpdateExpression;

/**
 * Writes an entity whether or not its item is stored: it updates the stored item, and puts the entity where none is
 * stored. The update is an {@see UpdateInput} keyed by the entity, and the put a {@see PutInput} of it.
 *
 * ```
 * // A stored item takes these paths from the entity
 * $client->upsert(new UpsertInput($order, ['status', 'meta.carrier']));
 *
 * // A stored item takes the update, and the entity is the item to create
 * $client->upsert(new UpsertInput($order, Update::increment('totalCents', 499)));
 * ```
 *
 * @template-covariant Entity of AbstractEntity
 */
final readonly class UpsertInput
{
    /**
     * @var class-string<Entity>
     */
    public string $class;

    /**
     * @var list<string>|UpdateExpression
     */
    public array|UpdateExpression $update;

    /**
     * @param Entity $entity - the item to put where none is stored, whose key addresses a stored item
     * @param list<string>|UpdateExpression $update - the paths a stored item takes from the entity, where a path the entity holds no value for is removed; or the update of a stored item, built with {@see Update}
     * @param ?FilterInterface $condition - checked against the stored item, and against an item without attributes where none is stored
     * @param Refresh $refresh - how the entity takes what the update wrote, as for an {@see UpdateInput}; after a put, it takes what the put wrote, unless this is {@see Refresh::None}
     */
    public function __construct(
        public AbstractEntity $entity,
        array|UpdateExpression $update,
        public ?FilterInterface $condition = null,
        public Refresh $refresh = Refresh::Full,
    ) {
        $this->class = $entity::class;
        $this->update = \is_array($update) ? array_values(array_unique($update)) : $update;
    }
}
