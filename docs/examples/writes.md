# Updates, conditions and transactions

- [Updates, conditions and transactions](#updates-conditions-and-transactions)
  - [Partial updates](#partial-updates)
  - [Nested updates](#nested-updates)
  - [Keeping the entity in sync](#keeping-the-entity-in-sync)
  - [Conditional writes](#conditional-writes)
  - [Transactions](#transactions)
  - [How writes are sent](#how-writes-are-sent)

These snippets build on the `OrderEntity` from [Basics](basics.md), with `$definition` set to its definition:

```php
$definition = $this->definitions->getByEntityClass(OrderEntity::class);
```

## Partial updates

A put replaces the whole item. An `UpdateInput` writes only the fields it names, so it doesn't need the rest of
the item and doesn't overwrite concurrent changes to other fields.

```php
use Shopware\DynamodbDalBundle\Client\Index;
use Shopware\DynamodbDalBundle\Client\Input\UpdateInput;

// By key, without reading the order first
$this->client->update($definition, new UpdateInput(
    new Index('c-42', 'o-1001'),
    ['status' => OrderStatus::Paid, 'note' => null],
));

// By entity: its key addresses the item, and the entity is updated as well
$this->client->update($definition, new UpdateInput($order, ['status' => OrderStatus::Paid]));
```

- `null` removes the attribute. Only nullable fields accept `null`.
- A field name the entity does not have fails with `UnknownFieldException`.
- Like DynamoDB's `UpdateItem`, updating a key that has no row creates one. The new row holds only the key
  and the written fields, so reading it fails if the entity has other required fields. Add the condition
  `Filter::exists('id')` when an update must not create a row.

## Nested updates

A path writes one map entry or one list element and leaves the rest of the attribute unchanged:

```php
$this->client->update($definition, new UpdateInput($order, [
    'meta.carrier' => 'dhl',  // set one map entry
    'meta.tracking' => null,  // remove one map entry
    'tags[0]' => 'priority',  // replace one list element
]));
```

- The attribute a path descends into has to exist. If the item has no `meta` map, DynamoDB rejects the whole
  update. A put stores a map field whose default is `[]` as an empty map, so paths into it work on every item
  written by a put.
- A map key may contain any character except `.`, `[` and `]`.
- A condition can check the entry it writes, for example `Filter::equals('meta.carrier', 'ups')`.

## Keeping the entity in sync

When an `UpdateInput` addresses an entity rather than an `Index`, its `refresh` argument controls what happens
to that entity:

| `refresh` | Single update | Update in a transaction |
|---|---|---|
| `true` (default) | The entity gets the whole stored item back from the update (`ReturnValues=ALL_NEW`) | The written fields are applied to the entity. If any written field is a nested path, the item is read back with a strongly consistent read |
| `null` | Same as `true` | The written top-level fields are applied to the entity; nested paths are not |
| `false` | The entity is left untouched | The entity is left untouched |

Several `UpdateInput`s passed to `update()` also run as a transaction, and `transactWrite()` always does.

## Conditional writes

Every write input takes a condition, built with the same `Filter` as a search. DynamoDB checks the condition
against the stored item and refuses the write if it doesn't hold.

```php
use Shopware\DynamodbDalBundle\Client\Input\DeleteInput;
use Shopware\DynamodbDalBundle\Client\Input\PutInput;
use Shopware\DynamodbDalBundle\Expression\Filter;

// Create the order, but never overwrite an existing one
$this->client->put($definition, new PutInput($order, Filter::notExists('id')));

// Only pay an open order
$this->client->update($definition, new UpdateInput(
    $order,
    ['status' => OrderStatus::Paid],
    Filter::equals('status', OrderStatus::Open),
));

// Only delete a cancelled order
$this->client->delete($definition, new DeleteInput($order, Filter::equals('status', OrderStatus::Cancelled)));
```

A single write whose condition fails throws AsyncAws's `ConditionalCheckFailedException`. Several inputs with
a condition run as a transaction: if one fails, none is applied, and a `TransactionCanceledException` is
thrown.

For optimistic locking, add a version field to the entity and require the version you read:

```php
#[Field]
public int $version = 0;
```

```php
use AsyncAws\DynamoDb\Exception\ConditionalCheckFailedException;

try {
    $this->client->update($definition, new UpdateInput(
        $order,
        ['status' => OrderStatus::Paid, 'version' => $order->version + 1],
        Filter::equals('version', $order->version),
    ));
} catch (ConditionalCheckFailedException) {
    // The order changed after it was read: reload it and try again, or report a conflict.
}
```

## Transactions

`transactWrite()` runs puts, updates and deletes across entity classes as a single all-or-nothing request.
Each operation names its entity class, and the bundle resolves the definition from it:

```php
use Shopware\DynamodbDalBundle\Client\Input\TransactWriteInput;

$this->client->transactWrite(
    new TransactWriteInput()
        ->with(OrderEntity::class, new UpdateInput(
            $order,
            ['status' => OrderStatus::Cancelled],
            Filter::equals('status', OrderStatus::Open),
        ))
        ->with(ReservationEntity::class, DeleteInput::fromIndex($order->id))
        ->with(OrderEventEntity::class, new PutInput($event)),
);
```

- DynamoDB allows up to 100 operations per transaction. A larger input is split into several transactions of
  100 operations, and each of those is atomic only on its own.
- If a transaction is cancelled only because another transaction conflicted with it, it is retried with a
  growing delay, for up to three attempts in total. Any other cancellation, such as a failed condition, is thrown as
  `TransactionCanceledException`. Its `getCancellationReasons()` tells which operation failed and why.
- DynamoDB refuses a transaction that includes more than one operation on the same item.
- After the transaction succeeds, puts and entity-keyed updates are applied to their entities, as described
  above.

## How writes are sent

`put()`, `update()` and `delete()` each take any number of inputs for one entity class. The bundle picks the
cheapest DynamoDB call that can carry them:

| Inputs | DynamoDB call | Atomic |
|---|---|---|
| One | `PutItem`, `UpdateItem` or `DeleteItem` | Yes |
| Several puts or deletes, none with a condition | `BatchWriteItem`, 25 per request; items DynamoDB leaves unprocessed are sent again | No |
| Several puts or deletes, any with a condition | `TransactWriteItems` | Per 100 operations |
| Several updates | `TransactWriteItems` | Per 100 operations |
