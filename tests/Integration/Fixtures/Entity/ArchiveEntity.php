<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\Entity;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Attribute\Field;
use Shopware\DynamodbDalBundle\Attribute\Table;

/**
 * A second table, so a read can span two of them and a transaction can write to both.
 */
#[Table(name: 'archive', hashKey: 'id')]
class ArchiveEntity extends AbstractEntity
{
    #[Field]
    public string $id;

    #[Field]
    public string $label = '';

    public static function create(string $id, string $label = ''): self
    {
        $entity = new self();
        $entity->id = $id;
        $entity->label = $label;

        return $entity;
    }
}
