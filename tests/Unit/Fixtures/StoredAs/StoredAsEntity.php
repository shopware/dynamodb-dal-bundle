<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Fixtures\StoredAs;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Attribute\Field;
use Shopware\DynamodbDalBundle\Attribute\Table;
use Shopware\DynamodbDalBundle\Definition\AttributeType;

/**
 * Two lists of strings that ask for their attribute type: one for a set, which only a serializer tried after the list
 * serializer stores, and one for the list it would be stored as anyway.
 */
#[Table(name: 'stored_as', hashKey: 'id')]
class StoredAsEntity extends AbstractEntity
{
    #[Field]
    protected string $id;

    /**
     * @var list<string>
     */
    #[Field(storedAs: AttributeType::StringSet)]
    protected array $labels = [];

    /**
     * @var list<string>
     */
    #[Field(storedAs: AttributeType::List)]
    protected array $tags = [];
}
