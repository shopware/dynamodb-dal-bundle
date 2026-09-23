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
final class DeleteInput
{
    /**
     * @param Entity|Index $key
     */
    public function __construct(
        public readonly AbstractEntity|Index $key,
        public readonly ?ExpressionInterface $conditionExpression = null,
    ) {
    }

    public static function fromIndex(mixed $hashValue, mixed $rangeValue = null): self
    {
        return new self(new Index($hashValue, $rangeValue));
    }
}
