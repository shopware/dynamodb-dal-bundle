<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\CompilerPass;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Attribute\Field;
use Shopware\DynamodbDalBundle\Attribute\Table;

#[Table(name: 'phpunit_test', hashKey: 'id')]
class EntityWithNestedListFieldEntity extends AbstractEntity
{
    #[Field]
    protected string $id;

    /**
     * @var list<list<string>>
     */
    #[Field]
    protected array $matrix = [];

    /**
     * @var array<string, list<string>>
     */
    #[Field]
    protected array $groups = [];

    public function getId(): string
    {
        return $this->id;
    }

    /**
     * @return list<list<string>>
     */
    public function getMatrix(): array
    {
        return $this->matrix;
    }

    /**
     * @param list<list<string>> $matrix
     */
    public function setMatrix(array $matrix): self
    {
        $this->matrix = $matrix;

        return $this;
    }

    /**
     * @return array<string, list<string>>
     */
    public function getGroups(): array
    {
        return $this->groups;
    }

    /**
     * @param array<string, list<string>> $groups
     */
    public function setGroups(array $groups): self
    {
        $this->groups = $groups;

        return $this;
    }
}
