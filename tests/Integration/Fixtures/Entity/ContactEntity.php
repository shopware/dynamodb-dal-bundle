<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\Entity;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Attribute\Field;
use Shopware\DynamodbDalBundle\Attribute\Table;

/**
 * An entity with a `JsonSerializable` value object and no field serializer of its own for it, so the
 * JSON serializer stores it and the normalizer reads it back.
 */
#[Table(name: 'contact', hashKey: 'id', normalizer: ContactEntityNormalizer::class)]
class ContactEntity extends AbstractEntity
{
    #[Field]
    public string $id;

    #[Field]
    public Address $address;
}
