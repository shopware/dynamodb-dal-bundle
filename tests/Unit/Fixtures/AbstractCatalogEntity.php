<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Fixtures;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Attribute\Field;
use Shopware\DynamodbDalBundle\Attribute\Table;

/**
 * A base its subclasses inherit attributes from — valid ones, so a test can tell the abstractness
 * apart from anything else that would stop a class compiling.
 */
#[Table(name: 'catalog', hashKey: 'tenantId')]
abstract class AbstractCatalogEntity extends AbstractEntity
{
    #[Field]
    protected string $tenantId;
}
