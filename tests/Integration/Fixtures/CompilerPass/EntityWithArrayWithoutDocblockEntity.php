<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\CompilerPass;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Attribute\Field;
use Shopware\DynamodbDalBundle\Attribute\Table;

#[Table(name: 'phpunit_test', hashKey: 'id')]
class EntityWithArrayWithoutDocblockEntity extends AbstractEntity
{
    #[Field]
    protected string $id;

    /**
     * @var array<int, mixed>
     */
    #[Field]
    protected array $items = [];

    public function getId(): string
    {
        return $this->id;
    }

    /**
     * @return array<int, mixed>
     */
    public function getItems(): array
    {
        return $this->items;
    }

    /**
     * @param array<int, mixed> $items
     */
    public function setItems(array $items): self
    {
        $this->items = $items;

        return $this;
    }
}
