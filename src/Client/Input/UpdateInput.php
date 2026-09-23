<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Client\Input;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Client\Index;
use Shopware\DynamodbDalBundle\Expression\Contract\ExpressionInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * @template-covariant Entity of AbstractEntity = never
 */
#[Exclude]
final class UpdateInput
{
    /**
     * @param Entity|Index $key - Item to update, either by its primary key or the entity itself
     * @param array<string, mixed> $fields - Fields to update with a value
     */
    public function __construct(
        public readonly AbstractEntity|Index $key,
        public readonly array $fields,
        public readonly ?ExpressionInterface $conditionExpression = null,
    ) {
    }
}
