# Updates, conditions and transactions

- [Updates, conditions and transactions](#updates-conditions-and-transactions)
  - [Partial updates](#partial-updates)
  - [Nested updates](#nested-updates)
  - [Update expressions](#update-expressions)
  - [Keeping the entity in sync](#keeping-the-entity-in-sync)
  - [Conditional writes](#conditional-writes)
  - [Transactions](#transactions)
  - [How writes are sent](#how-writes-are-sent)

These snippets build on the `OrderEntity` from [Basics](basics.md).

## Partial updates

A put replaces the whole item. An `UpdateInput` writes only the fields it names, so it doesn't need the rest of
the item and doesn't overwrite concurrent changes to other fields.

```php
use Shopware\DynamodbDalBundle\Client\Index;
use Shopware\DynamodbDalBundle\Client\Input\UpdateInput;

// By key, without reading the order first
$this->client->update(OrderEntity::class, new UpdateInput(
    new Index('c-42', 'o-1001'),
    ['status' => OrderStatus::Paid, 'note' => null],
));

// By entity: its key addresses the item, and the entity is updated as well
$this->client->update(OrderEntity::class, new UpdateInput($order, ['status' => OrderStatus::Paid]));
```

- `null` removes the attribute. Only nullable fields accept `null`.
- A field name the entity does not have fails with `UnknownFieldException`.
- Unlike DynamoDB's `UpdateItem`, an update never creates a row. If no row has the key, the update fails the same way a failed [condition](#conditional-writes) does. Use a put to create a row.

## Nested updates

A path writes one map entry or one list element and leaves the rest of the attribute unchanged:

```php
$this->client->update(OrderEntity::class, new UpdateInput($order, [
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

## Update expressions

An array of fields only sets and removes values you already know. `Update` builds an expression whose actions
DynamoDB evaluates against the stored item, so two writers that count or append at the same time both land. Each
method returns a whole expression, and `Update::with()` combines several, as `Filter::and()` combines filters. A basic
update sets the values you already know in one `setFields()`, next to the actions:

```php
use Shopware\DynamodbDalBundle\Expression\Update;

$this->client->update(OrderEntity::class, new UpdateInput(
    $order,
    // same as Update::setFields(...)
    ['status' => OrderStatus::Paid, 'meta.channel' => 'web'],
));

// A single one needs no with()
$this->client->update(OrderEntity::class, new UpdateInput($order, Update::increment('totalCents', 499)));
```

A complex one names each path on its own. `set()` and `remove()` do what `setFields()` does for one path, and
`setIfNotExists()` writes a value only where none is stored yet:

```php
$this->client->update(OrderEntity::class, new UpdateInput(
    $order,
    Update::with(
        Update::set('status', OrderStatus::Paid), // like ['status' => OrderStatus::Paid]
        Update::remove('note'),                   // like ['note' => null]
        Update::increment('totalCents', 499),
        Update::append('tags', ['gift-wrapped']),
        Update::setIfNotExists('meta.channel', 'web'),
    ),
));
```

| Method | DynamoDB | Writes |
|---|---|---|
| `set($path, $value)`, `remove($path)`, `setFields([...])` | `SET #p = :v`, `REMOVE #p` | The value, or removes it for `null`, like an array of fields |
| `setIfNotExists($path, $value)` | `SET #p = if_not_exists(#p, :v)` | The value, unless the path holds one already. `null` writes nothing |
| `increment($path, $by = 1)`, `decrement($path, $by = 1)` | `ADD #p :by` | The stored number plus or minus the step. A missing number counts as 0 |
| `append($path, [...])`, `prepend($path, [...])` | `SET #p = list_append(…)` | The stored list with the values at its end or start. A missing list counts as empty. No values write nothing |
| `add($path, $value)` | `ADD #p :v` | A number added to the stored one, or elements added to a set |
| `delete($path, $value)` | `DELETE #p :v` | A set without the given elements |
| `with(...)` | | Everything the expressions and actions it combines write |

- `with()` takes expressions and [actions of your own](extending.md#an-update-action-of-your-own), nested as deep as
  you like. A path set by several takes the last value.
- An expression's own `with()` extends it into a new one, e.g. `$update->with(Update::remove('note'))`, and
  leaves it as it is.
- An array of fields given to `UpdateInput` is shorthand for `Update::setFields([...])`.
- Every path may address a map entry or list element, as `meta.channel` does above. The attribute it descends into
  has to exist, as for [nested updates](#nested-updates).
- Operands go through the field's serializer, so `increment()` on an `int` field refuses a step of `0.5`.
- The entity's normalizer sees the fields that are set or removed, as it does for a put. In the same call it sees
  the value of a `setIfNotExists()` and the elements of an `append()` or `prepend()` under their path, as if they
  were set, and whatever it leaves there is what the action writes. It sees the value offered, not the one
  DynamoDB keeps, and never the operand of another action: a step to count by, or elements to add to or delete
  from a set, are no value of the field.
- An update that has nothing to write throws `UpdateEmptyException` before any request is sent.
- A path given two values, as a field and the value of a `setIfNotExists()` or `append()`, or as the values of two
  of them, throws `UpdateDuplicatePathException` before any request is sent. The normalizer takes one value per
  path, so one of them would be lost.
- DynamoDB rejects any other update whose paths overlap, such as `set('totalCents', 0)` together with
  `increment('totalCents')`, or `increment('totalCents')` twice.

Anything else DynamoDB's update syntax allows can be [an action of your own](extending.md#an-update-action-of-your-own).

## Keeping the entity in sync

When an `UpdateInput` addresses an entity rather than an `Index`, its `refresh` argument controls what happens
to that entity:

| `refresh` | Single update | Update in a transaction |
|---|---|---|
| `true` (default) | The entity gets the whole stored item back from the update (`ReturnValues=ALL_NEW`) | The written fields are applied to the entity. If a written field is a nested path, or the update has an action, the item is read back with a strongly consistent read |
| `null` | Same as `true` | The written top-level fields are applied to the entity; nested paths and actions are not |
| `false` | The entity is left untouched | The entity is left untouched |

Several `UpdateInput`s passed to `update()` also run as a transaction, and `transactWrite()` always does.

## Conditional writes

Every write input takes a condition, built with the same `Filter` as a search. DynamoDB checks the condition
against the stored item and refuses the write if it doesn't hold. An update always checks that the item exists,
and its own condition is added to that check.

```php
use Shopware\DynamodbDalBundle\Client\Input\DeleteInput;
use Shopware\DynamodbDalBundle\Client\Input\PutInput;
use Shopware\DynamodbDalBundle\Expression\Filter;

// Create the order, but never overwrite an existing one
$this->client->put(OrderEntity::class, new PutInput($order, Filter::notExists('id')));

// Only pay an open order
$this->client->update(OrderEntity::class, new UpdateInput(
    $order,
    ['status' => OrderStatus::Paid],
    Filter::equals('status', OrderStatus::Open),
));

// Only delete a cancelled order
$this->client->delete(OrderEntity::class, new DeleteInput($order, Filter::equals('status', OrderStatus::Cancelled)));
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
    $this->client->update(OrderEntity::class, new UpdateInput(
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
Each operation names its entity class, just as a single `put()`, `update()` or `delete()` does:

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
