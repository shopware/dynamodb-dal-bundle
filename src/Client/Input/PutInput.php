<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Client\Input;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Expression\Contract\ExpressionInterface;

/**
 * @template-covariant Entity of AbstractEntity
 */
final class PutInput
{
    /**
     * @param Entity $entity - Entity to upsert
     */
    public function __construct(
        public readonly AbstractEntity $entity,
        public readonly ?ExpressionInterface $conditionExpression = null,
    ) {
    }
}
