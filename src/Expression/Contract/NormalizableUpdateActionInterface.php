<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Expression\Contract;

use Shopware\DynamodbDalBundle\Serializer\AbstractNormalizer;

/**
 * An update action whose input is a value of its field, such as the value `setIfNotExists()` stores or the elements `append()` adds.
 * The entity's {@see AbstractNormalizer} sees that input under the action's path, like a field the update sets, and never the action itself.
 * The action then takes back what the normalizer left.
 *
 * An action whose input is no value of its field, such as a step to count by, implements {@see UpdateActionInterface} alone.
 */
interface NormalizableUpdateActionInterface extends UpdateActionInterface
{
    /**
     * The path the input is a value of.
     */
    public function getPath(): string;

    /**
     * The input, as the normalizer sees it.
     */
    public function getValue(): mixed;

    /**
     * This action with the input the normalizer left, `null` where it removed the value. It must not change this one.
     * What that input writes, if anything, is up to {@see UpdateActionInterface::compile()}.
     */
    public function withValue(mixed $value): self;
}
