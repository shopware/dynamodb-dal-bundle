<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\Entity;

use Shopware\DynamodbDalBundle\Serializer\AbstractNormalizer;
use Shopware\DynamodbDalBundle\Serializer\NormalizerContext;

/**
 * Only reads: writing needs nothing, so `normalize()` is left as it is.
 */
class ContactEntityNormalizer extends AbstractNormalizer
{
    public function denormalize(NormalizerContext $context): void
    {
        // The JSON serializer reads the address back as the array it wrote.
        $address = $context->get('address');
        if (\is_array($address)) {
            /** @var array{street: string, city: string} $address */
            $context->set('address', Address::fromArray($address));
        }
    }
}
