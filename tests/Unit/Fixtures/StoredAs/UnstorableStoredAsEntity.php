<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Fixtures\StoredAs;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Attribute\Field;
use Shopware\DynamodbDalBundle\Attribute\Table;
use Shopware\DynamodbDalBundle\Definition\AttributeType;

/**
 * A string that asks to be stored as a number, which no serializer of a string does.
 */
#[Table(name: 'unstorable_stored_as', hashKey: 'id')]
class UnstorableStoredAsEntity extends AbstractEntity
{
    #[Field]
    protected string $id;

    #[Field(storedAs: AttributeType::Number)]
    protected string $code;
}
