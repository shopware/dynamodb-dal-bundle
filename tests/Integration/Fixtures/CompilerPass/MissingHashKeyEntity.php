<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\CompilerPass;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Attribute\Field;
use Shopware\DynamodbDalBundle\Attribute\Table;

/**
 * @phpstan-ignore-next-line arguments.count -- intentionally omits the required $hashKey
 */
#[Table(name: 'phpunit_test')]
class MissingHashKeyEntity extends AbstractEntity
{
    #[Field]
    protected string $value;
}
