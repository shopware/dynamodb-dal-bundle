<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\CompilerPass;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Attribute\Field;
use Shopware\DynamodbDalBundle\Attribute\Table;

#[Table(name: 'phpunit_test', hashKey: 'id')]
class EntityWithMapUnsupportedValueTypeEntity extends AbstractEntity
{
    #[Field]
    protected string $id;

    /**
     * Value type \stdClass has no field serializer - should fail at compile time.
     *
     * @var array<string, \stdClass>
     */
    #[Field]
    protected array $meta = [];

    public function getId(): string
    {
        return $this->id;
    }
}
