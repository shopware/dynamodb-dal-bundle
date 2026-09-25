<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Client\Input;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Client\Index;
use Shopware\DynamodbDalBundle\Expression\Contract\FilterInterface;
use Shopware\DynamodbDalBundle\Expression\Update;
use Shopware\DynamodbDalBundle\Expression\Update\UpdateExpression;

/**
 * @template-covariant Entity of AbstractEntity = never
 */
final class UpdateInput
{
    public readonly UpdateExpression $update;

    /**
     * @param Entity|Index $key - Item to update, either by its primary key or the entity itself
     * @param array<string, mixed>|UpdateExpression $update - Fields keyed by path, set to their value or removed for `null`, or an expression built with {@see Update}
     * @param ?FilterInterface $conditionExpression - Checked on top of the item existing, which every update requires
     * @param ?bool $refresh - `null` = best effort of keeping the entity up-to-date. Nested paths and actions will not be applied.
     *                       `true` = entity changes are applied, if necessary a readback is performed.
     *                       `false` = entity is not updated at all, even if it is an entity and the update could be applied.
     */
    public function __construct(
        public readonly AbstractEntity|Index $key,
        array|UpdateExpression $update,
        public readonly ?FilterInterface $conditionExpression = null,
        public readonly ?bool $refresh = true,
    ) {
        $this->update = \is_array($update) ? Update::setFields($update) : $update;
    }
}
