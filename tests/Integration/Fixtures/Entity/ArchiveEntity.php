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

    /**
     * So a transaction can write a nested path on this table too, not only on the record one.
     *
     * @var array<string, string>
     */
    #[Field]
    public array $meta = [];

    /**
     * A DynamoDB string set, through a field serializer of the application's own, for `ADD` and `DELETE`
     * on a set. `null` while the set is empty, since DynamoDB stores no empty set.
     */
    #[Field]
    public ?StringSet $labels = null;

    public static function create(string $id, string $label = ''): self
    {
        $entity = new self();
        $entity->id = $id;
        $entity->label = $label;

        return $entity;
    }
}
