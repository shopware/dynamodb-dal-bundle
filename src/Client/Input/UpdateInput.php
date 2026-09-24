<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Client\Input;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Client\Index;
use Shopware\DynamodbDalBundle\Expression\Contract\ExpressionInterface;

/**
 * @template-covariant Entity of AbstractEntity = never
 */
final class UpdateInput
{
    /**
     * @param Entity|Index $key - Item to update, either by its primary key or the entity itself
     * @param array<string, mixed> $fields - Fields to update with a value
     * @param ?bool $refresh - `null` = best effort of keeping the entity up-to-date. Nested updates will not be applied.
     *                       `true` = entity changes are applied, if necessary a readback is performed.
     *                       `false` = entity is not updated at all, even if it is an entity and the update could be applied.
     */
    public function __construct(
        public readonly AbstractEntity|Index $key,
        public readonly array $fields,
        public readonly ?ExpressionInterface $conditionExpression = null,
        public readonly ?bool $refresh = true,
    ) {
    }
}
