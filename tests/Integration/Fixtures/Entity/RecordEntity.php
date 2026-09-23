<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\Entity;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Attribute\Field;
use Shopware\DynamodbDalBundle\Attribute\Table;
use Shopware\DynamodbDalBundle\Definition\IndexSchema;

/**
 * The workhorse of the DynamoDB-backed suites: a composite-key table with one global secondary index
 * and one field per supported serializer, so a single table covers key reads, queries, scans, filters
 * and nested document updates.
 */
#[Table(
    name: 'record',
    hashKey: 'tenantId',
    rangeKey: 'id',
    indexes: [new IndexSchema('statusIndex', 'status', 'createdAt')],
)]
class RecordEntity extends AbstractEntity
{
    #[Field]
    public string $tenantId;

    #[Field]
    public string $id;

    #[Field]
    public RecordStatus $status = RecordStatus::Open;

    #[Field]
    public \DateTimeImmutable $createdAt;

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
     * @var array<string, string>
     */
    #[Field]
    public array $meta = [];

    /**
     * @var array<string, list<string>>
     */
    #[Field]
    public array $groups = [];

    /**
     * No `@var` shape, so this is the JSON-serializer field.
     */
    #[Field]
    public array $payload = [];

    #[Field]
    public ?\DateTimeImmutable $deletedAt = null;

    /**
     * @param list<string> $tags
     */
    public static function create(
        string $tenantId,
        string $id,
        RecordStatus $status = RecordStatus::Open,
        ?\DateTimeImmutable $createdAt = null,
        ?string $name = null,
        int $counter = 0,
        array $tags = [],
    ): self {
        $entity = new self();
        $entity->tenantId = $tenantId;
        $entity->id = $id;
        $entity->status = $status;
        $entity->createdAt = $createdAt ?? new \DateTimeImmutable('@1700000000');
        $entity->name = $name;
        $entity->counter = $counter;
        $entity->tags = $tags;

        return $entity;
    }
}
