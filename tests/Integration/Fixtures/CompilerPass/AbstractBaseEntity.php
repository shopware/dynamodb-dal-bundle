<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\CompilerPass;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Attribute\Field;
use Shopware\DynamodbDalBundle\Attribute\Table;

/**
 * A base its entities extend, with attributes valid enough that only the abstractness can be what
 * keeps it out of the container.
 */
#[Table(name: 'phpunit_test', hashKey: 'id')]
abstract class AbstractBaseEntity extends AbstractEntity
{
    #[Field]
    protected string $id;
}
