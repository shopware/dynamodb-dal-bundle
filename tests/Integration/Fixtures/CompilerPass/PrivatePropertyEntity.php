<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\CompilerPass;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Attribute\Field;
use Shopware\DynamodbDalBundle\Attribute\Table;

#[Table(name: 'phpunit_test', hashKey: 'value')]
class PrivatePropertyEntity extends AbstractEntity
{
    #[Field]
    /**
     * @phpstan-ignore-next-line property.unused
     */
    private string $value;
}
