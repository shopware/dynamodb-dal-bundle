<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Client\Write;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Serializer\SerializedKeyResult;
use AsyncAws\DynamoDb\ValueObject\TransactWriteItem;

/**
 * A put, update or delete as DynamoDB takes it, alone or in a transaction, and how its entity is brought up to date
 * once it is stored.
 *
 * @internal
 *
 * @template-covariant Request of array<string, mixed>
 */
final readonly class PreparedWrite
{
    /**
     * @param 'Put'|'Update'|'Delete' $type - the member of a `TransactWriteItem` it goes out as
     * @param SerializedKeyResult<AbstractEntity> $key - of the item it writes, and with it the item's definition
     * @param Request $request - the input of `PutItem`, `UpdateItem` or `DeleteItem`, which is also what a transaction takes
     * @param ?WriteBack $writeBack - where the write returns no item; `null` leaves the entity as it is
     */
    public function __construct(
        public string $type,
        public SerializedKeyResult $key,
        public array $request,
        public ?WriteBack $writeBack = null,
    ) {
    }

    public function toTransactItem(): TransactWriteItem
    {
        return new TransactWriteItem([$this->type => $this->request]);
    }
}
