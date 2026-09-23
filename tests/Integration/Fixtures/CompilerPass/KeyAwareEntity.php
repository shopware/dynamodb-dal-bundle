<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\CompilerPass;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Attribute\Field;
use Shopware\DynamodbDalBundle\Attribute\Table;
use Shopware\DynamodbDalBundle\Definition\IndexSchema;

#[Table(
    name: 'phpunit_test',
    hashKey: 'tenantId',
    rangeKey: 'createdAt',
    indexes: [
        new IndexSchema('statusCreatedAtIndex', hashKey: 'status', rangeKey: 'createdAt'),
        new IndexSchema('companyIdIndex', hashKey: 'companyId'),
    ],
)]
class KeyAwareEntity extends AbstractEntity
{
    #[Field]
    protected string $tenantId;

    #[Field]
    protected string $status;

    #[Field]
    protected int $companyId;

    #[Field]
    protected \DateTimeImmutable $createdAt;
}
