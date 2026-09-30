<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\CompilerPass;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Attribute\Field;
use Shopware\DynamodbDalBundle\Attribute\Table;
use Shopware\DynamodbDalBundle\Definition\AttributeType;

/**
 * Two properties of one type, stored in two ways: `amount` by whichever serializer comes first, `total` as a map.
 */
#[Table(name: 'phpunit_test', hashKey: 'id')]
class EntityWithTwoMoneyStoragesEntity extends AbstractEntity
{
    #[Field]
    protected string $id;

    #[Field]
    protected Money $amount;

    #[Field(storedAs: AttributeType::Map)]
    protected Money $total;
}
