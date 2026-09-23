<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Fixtures\Entity;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Attribute\Field;
use Shopware\DynamodbDalBundle\Attribute\Table;
use Shopware\DynamodbDalBundle\Definition\IndexSchema;
use Symfony\Component\Uid\Uuid;

#[Table(
    name: 'test',
    hashKey: 'id',
    rangeKey: 'createdAt',
    indexes: [new IndexSchema('status-index', 'status', 'createdAt')],
)]
class TestEntity extends AbstractEntity
{
    #[Field]
    public Uuid $id;

    #[Field]
    public \DateTimeImmutable $createdAt;

    #[Field]
    public TestStatus $status = TestStatus::Open;

    #[Field]
    public ?string $name = null;

    #[Field]
    public int $counter = 0;

    #[Field]
    public bool $active = true;

    #[Field]
    public float $amount = 0.0;

    /**
     * @var list<string>
     */
    #[Field]
    public array $tags = [];

    /**
     * @var array<string, int>
     */
    #[Field]
    public array $quantities = [];

    /**
     * @var array<string, list<string>>
     */
    #[Field]
    public array $nested = [];

    /**
     * No `@var` shape, so the compiler picks the JSON serializer instead of the map one.
     */
    #[Field]
    public array $payload = [];
}
