<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\CompilerPass;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Attribute\Field;
use Shopware\DynamodbDalBundle\Attribute\Table;

#[Table(name: 'phpunit_test', hashKey: 'id')]
class EntityWithMapFieldEntity extends AbstractEntity
{
    #[Field]
    protected string $id;

    /**
     * @var array<string, string>
     */
    #[Field]
    protected array $meta = [];

    public function getId(): string
    {
        return $this->id;
    }

    /**
     * @return array<string, string>
     */
    public function getMeta(): array
    {
        return $this->meta;
    }

    /**
     * @param array<string, string> $meta
     */
    public function setMeta(array $meta): self
    {
        $this->meta = $meta;

        return $this;
    }
}
