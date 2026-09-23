<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Fixtures;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Attribute\Field;
use Shopware\DynamodbDalBundle\Attribute\Table;
use Shopware\DynamodbDalBundle\Definition\IndexSchema;

/**
 * An entity carrying one of each shape {@see \Shopware\DynamodbDalBundle\DefinitionBuilder} compiles:
 * a key schema with a sort key, an index, a scalar field and a nested collection field.
 */
#[Table(
    name: 'catalog',
    hashKey: 'tenantId',
    rangeKey: 'createdAt',
    indexes: [new IndexSchema('statusIndex', hashKey: 'status', rangeKey: 'createdAt')],
)]
class CatalogEntity extends AbstractEntity
{
    #[Field]
    protected string $tenantId;

    #[Field]
    protected string $status;

    #[Field]
    protected \DateTimeImmutable $createdAt;

    /**
     * @var array<string, list<string>>
     */
    #[Field]
    protected array $groups = [];
}
