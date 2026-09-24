<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\Entity;

use Shopware\DynamodbDalBundle\Serializer\AbstractNormalizer;

/**
 * @extends AbstractNormalizer<array<string, mixed>, array<string, mixed>>
 */
class ContactEntityNormalizer extends AbstractNormalizer
{
    public function normalize(array $fields, array $keys): array
    {
        return $fields;
    }

    public function denormalize(array $fields, array $keys): array
    {
        // The JSON serializer reads the address back as the array it wrote.
        if (isset($keys['address']) && \is_array($fields['address'] ?? null)) {
            /** @var array{street: string, city: string} $address */
            $address = $fields['address'];
            $fields['address'] = Address::fromArray($address);
        }

        return $fields;
    }
}
