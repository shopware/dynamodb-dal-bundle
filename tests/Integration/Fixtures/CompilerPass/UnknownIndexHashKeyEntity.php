<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\CompilerPass;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Attribute\Field;
use Shopware\DynamodbDalBundle\Attribute\Table;
use Shopware\DynamodbDalBundle\Definition\IndexSchema;

#[Table(
    name: 'phpunit_test',
    hashKey: 'value',
    indexes: [new IndexSchema('phpunitIndex', hashKey: 'doesNotExist')],
)]
class UnknownIndexHashKeyEntity extends AbstractEntity
{
    #[Field]
    protected string $value;
}
