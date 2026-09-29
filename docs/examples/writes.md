# Updates, conditions and transactions

- [Updates, conditions and transactions](#updates-conditions-and-transactions)
  - [Choosing a write](#choosing-a-write)
  - [Partial updates](#partial-updates)
  - [Nested updates](#nested-updates)
  - [Update expressions](#update-expressions)
  - [Upserts](#upserts)
  - [Keeping the entity in sync](#keeping-the-entity-in-sync)
  - [Conditional writes](#conditional-writes)
  - [Batches](#batches)
  - [Transactions](#transactions)

These snippets build on the `OrderEntity` and the injected `Client` from [Basics](basics.md). The batch and
transaction snippets also use a `ReservationEntity`, keyed by the order ID alone. [Exceptions](exceptions.md) lists
what each write can throw.

## Choosing a write

| Method | Writes | All or nothing | Takes a condition |
|---|---|---|---|
| `put()` | One whole entity, creating or replacing its row. See [Basics](basics.md#putting-and-deleting) | Yes | Yes |
| `update()` | Some fields of one entity that is already stored | Yes | Yes |
| `upsert()` | One entity, updating its stored row or putting it where none is stored | Yes | Yes |
| `delete()` | Removes one entity. See [Basics](basics.md#putting-and-deleting) | Yes | Yes |
| `batchWrite()` | Many puts and deletes | No | No |
| `transactWrite()` | Puts, updates and deletes of several entities | Up to 100 operations | Yes |

## Partial updates

A put replaces the whole row. An `UpdateInput` writes only the fields it names. It doesn't need the rest of the entity,
and it doesn't overwrite concurrent changes to other fields.

```php
use Shopware\DynamodbDalBundle\Client\Input\UpdateInput;
use Shopware\DynamodbDalBundle\Client\Key;

// By key, without reading the order first
$this->client->update(new UpdateInput(
    new Key(OrderEntity::class, 'c-42', 'o-1001'),
    ['status' => OrderStatus::Paid, 'note' => null],
));

// By entity: its key addresses the row, and the entity is updated as well
$this->client->update(new UpdateInput($order, ['status' => OrderStatus::Paid]));
```

The fields are an array keyed by field name. `null` removes the field's attribute from the row, so only a nullable
field accepts `null`.

### Pitfalls

- An update never creates a row, unlike DynamoDB's `UpdateItem`. If no row has the key, the update fails the same
  way a failed [condition](#conditional-writes) does. Use a put or an [upsert](#upserts) to create the row.
- An update cannot change the table's key fields, here `customerId` and `id`. DynamoDB rejects it. To move an entity to
  another key, put it under the new key and delete the old one in the same [transaction](#transactions).

## Nested updates

A path writes one map entry or one list element. The rest of the attribute stays as it is.

```php
$this->client->update(new UpdateInput($order, [
    'meta.carrier' => 'dhl',  // set one map entry
    'meta.tracking' => null,  // remove one map entry
    'tags[0]' => 'priority',  // replace one list element
]));
```

A map key may contain any character except `.`, `[` and `]`. A [condition](#conditional-writes) can check the entry
the update writes, for example `Filter::equals('meta.carrier', 'ups')`.

### Pitfalls

- The attribute a path descends into has to exist. If the row has no `meta` map, DynamoDB rejects the whole update.
- A put stores a map field whose default is `[]` as an empty map, so paths into it work on every row a put wrote.
  A row written before the field was added to the entity has no map, and a path into it fails there.
- An index past the end of a list doesn't write at that index. DynamoDB appends the value to the list instead, so
  `tags[9]` on a list of two elements writes `tags[2]`.
- `null` removes a list element, and the elements after it move up by one. `['tags[1]' => null]` on
  `['a', 'b', 'c']` leaves `['a', 'c']`.

## Update expressions

An array of fields sets and removes values you already know. `Update` adds actions that DynamoDB evaluates against
the stored row, such as counting or appending. When two writers count or append at the same time, both changes land.

Each `Update` method returns a whole expression, so a single action needs nothing else:

```php
use Shopware\DynamodbDalBundle\Expression\Update;

$this->client->update(new UpdateInput($order, Update::increment('totalCents', 499)));
```

`Update::with()` combines several expressions, as `Filter::and()` combines filters. `set()` and `remove()` each write
one path, as an array of fields does, so they can be combined with actions:

```php
$this->client->update(new UpdateInput(
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
| `set($path, $value)`, `remove($path)`, `setFields([...])` | `SET #p = :v`, `REMOVE #p` | The value, or removes the attribute for `null`, like an array of fields |
| `setIfNotExists($path, $value)` | `SET #p = if_not_exists(#p, :v)` | The value, unless the path already holds one. `null` writes nothing |
| `increment($path, $by = 1)`, `decrement($path, $by = 1)` | `ADD #p :by` | The stored number plus or minus the step. A missing number counts as 0 |
| `append($path, [...])`, `prepend($path, [...])` | `SET #p = list_append(…)` | The stored list with the values added at its end or start. A missing list counts as empty. No values write nothing |
| `addToSet($path, $elements)` | `ADD #p :v` | The stored set with the elements added. A missing set is created |
| `removeFromSet($path, $elements)` | `DELETE #p :v` | The stored set without the elements |
| `with(...)` | | Everything the combined expressions and actions write |

An array of fields given to `UpdateInput` is shorthand for `Update::setFields([...])`. Anything else DynamoDB's update
syntax allows can be [an action of your own](extending.md#an-update-action-of-your-own).

`with()` takes such actions too, and expressions nested as deep as you like. If several `set()` or `setFields()`
calls name the same path, the last value wins. An expression's own `with()` returns a new expression with more added,
such as `$update->with(Update::remove('note'))`. The original expression stays unchanged.

Every path may address a map entry or a list element, as `meta.channel` does above. The rules of
[nested updates](#nested-updates) apply.

The bundle has no set type of its own. `addToSet()` and `removeFromSet()` take a value of a set type that a
[field serializer of yours](extending.md#a-field-type-of-your-own) stores as a DynamoDB set.

Each path can take only one value per update. The bundle refuses, before sending, an update that gives one path two
values, such as a field and a `setIfNotExists()` for the same path. It also refuses an update that has nothing to
write.

### How it works

- Every operand goes through the field's serializer, like a value of the field. An `increment()` on an `int` field
  therefore refuses a step of `0.5`.
- The entity's [normalizer](extending.md#a-normalizer) sees what the update stores as given, each under its path: the
  fields it sets or removes, the value of a `setIfNotExists()`, and the elements of an `append()` or `prepend()`.
  Whatever the normalizer leaves there is what the update writes.
- The normalizer never sees the step of an `increment()` or the elements of a set, because they are not values of
  the field.
- `removeFromSet()` of a set's last elements removes the attribute, because DynamoDB stores no empty sets.

### Pitfalls

- The bundle only refuses a path that is given two values. DynamoDB rejects every other overlap of paths in one
  update, for example:
  - `set('totalCents', 0)` together with `increment('totalCents')`
  - `increment('totalCents')` twice
  - `['meta' => [], 'meta.carrier' => 'dhl']`
- The normalizer sees the value that `setIfNotExists()` offers, not the value DynamoDB keeps. If the path already
  holds a value, the stored value stays, even though the normalizer saw the new one.
- DynamoDB rejects `addToSet()` with an empty set.

## Upserts

An upsert writes one entity whether or not its row is stored. It updates a stored row, and puts the entity where no
row is stored. The update is the same as an `UpdateInput` keyed by the entity, and the put the same as a `PutInput`
of it.

```php
use Shopware\DynamodbDalBundle\Client\Input\UpsertInput;

// An order synced from the shop: a stored order only takes the shop's status, total and carrier
$order = new OrderEntity();
$order->customerId = 'c-42';
$order->id = 'o-1001';
$order->createdAt = new \DateTimeImmutable();
$order->status = OrderStatus::Paid;
$order->totalCents = 4_990;
$order->meta = ['carrier' => 'dhl'];

$this->client->upsert(new UpsertInput($order, ['status', 'totalCents', 'meta.carrier']));
```

The second argument says what a stored row takes. A list of paths takes each path's value from the entity, as an
update of `['status' => $order->status, ...]` would. A path may address a map entry or a list element, as
`meta.carrier` does, and the other entries of a stored map stay as they are. A path that the entity holds no value
for, such as a map key it lacks, is removed from the stored row. A key field in the list writes nothing, since the key
already names the row.

A path into a field takes its value from the row the put would store, not from the property. A field that a
[field serializer](extending.md#a-field-type-of-your-own) or the [normalizer](extending.md#a-normalizer) stores as a
map, such as an object, therefore works like an array. The bundle refuses a path into a field that the row doesn't
store as a map or a list there, before it sends anything.

An [update expression](#update-expressions) instead is written to a stored row as it is, and the entity is the row that
a new one gets:

```php
// Add a line to the order. A new order holds the entity, with the line as its total
$order->totalCents = 499;

$this->client->upsert(new UpsertInput($order, Update::increment('totalCents', 499)));
```

The third argument is a condition, and the fourth a `refresh`, as for an update. The entity has to carry its key,
because the update addresses the stored row by it. An upsert takes two requests, so it can't be part of a
[transaction](#transactions).

`upsert()` returns which of the two writes was stored, for a caller that only acts on one of them:

```php
use Shopware\DynamodbDalBundle\Client\Output\UpsertOutcome;

if ($this->client->upsert(new UpsertInput($order, ['status'])) === UpsertOutcome::Created) {
    // The order was new, so no stored row was updated
}
```

### How it works

- The bundle prepares the update and the put before it sends either. An entity that can't be put is therefore refused,
  even where a row is stored.
- It sends the update first. The update is conditioned on the row existing, as every update is. Where no row is
  stored, DynamoDB refuses the update, and the bundle puts the entity, conditioned on its key being free. Both ask
  DynamoDB to return the stored row if their condition fails (`ReturnValuesOnConditionCheckFailure=ALL_OLD`), which
  tells a missing row from a condition of yours that fails on a stored one.
- If another writer creates the row between the update and the put, the put fails and returns that row, and the
  bundle sends the update again. If the row is deleted again before that second update, the bundle gives up with an
  `UpsertContentionException`, and neither write is stored. That is not a failed condition, so it is not a
  `ConditionalCheckFailedException`.
- Only one of the two writes is stored. The row is therefore either the stored row with the update applied, or the
  entity.
- A whole field in a list of paths takes the entity's value. A path into a field is read from the row the put would
  store, and its value there is deserialized with the type of the field's values, as `#[Field]` declares it.
- The entity's [normalizer](extending.md#a-normalizer) runs as `Update` for the update and as `Put` for the put, as for
  each write on its own. A `createdAt` it generates for a put therefore reaches only a new row, and an `updatedAt` it
  stamps for an update only a stored one.
- The entity is brought up to date as after the write that was stored. After the update, it takes the stored row.
  After the put, it takes the values the normalizer generated. `Refresh::None` leaves it as it is after either.

### Pitfalls

- A named path takes exactly the value the entity holds there, and a default counts as a value. A named path is not
  "written if the entity has something". Take a stored order with the note "Leave at the door" and
  `meta = ['carrier' => 'dhl', 'tracking' => 'T-1']`, and a sync that sets only the status on a fresh entity:

  ```php
  $order = new OrderEntity(); // key, createdAt and totalCents set; note and meta stay at null and []
  $order->status = OrderStatus::Paid;

  $this->client->upsert(new UpsertInput($order, ['status', 'note', 'meta.tracking']));
  // Sends SET #status = :status REMOVE #note, #meta.#tracking
  ```

  The stored order gets the new status, but it loses its note and its tracking number. `meta.carrier` stays, and so
  do `totalCents` and `createdAt`, which are not named. Name only the paths the caller sets, here `['status']`. Where
  no row is stored, the same call puts the entity with its defaults, which overwrites nothing.
- A new row costs two writes, because DynamoDB charges write capacity for the update it refuses. A stored row costs
  one.
- The rules of [nested updates](#nested-updates) apply to a stored row. A path into a map fails on a row that has no
  such map, such as a row written before the field was added.
- A value at a path into a field passes the normalizer twice: as part of the whole field, for the row the put would
  store, and on its own, for the update. A normalizer that changes such a value in a way that doesn't hold up to a
  second pass, such as adding a prefix, changes it twice in a stored row.
- Where no row is stored, DynamoDB checks the condition against a row without attributes, as it does for a put. A
  comparison is then false, so a condition like `Filter::lessThan('totalCents', 10_000)` refuses to create the row.
  Allow for the new row: `Filter::or(Filter::notExists('totalCents'), Filter::lessThan('totalCents', 10_000))`.

## Keeping the entity in sync

When an `UpdateInput` addresses an entity rather than a `Key`, its `refresh` argument decides how the entity takes on
what the update wrote:

| `refresh` | Single update | Update in a transaction |
|---|---|---|
| `Refresh::Full` (default) | The entity takes the whole stored row | The entity takes the written fields. If the update writes a nested path or has an action, the bundle reads the entity back |
| `Refresh::WithoutReadBack` | Same as `Refresh::Full` | The entity takes the fields written as a whole. Nested paths and actions are not applied |
| `Refresh::None` | The entity stays as it is | The entity stays as it is |

```php
use Shopware\DynamodbDalBundle\Client\Input\Refresh;

$this->client->update(new UpdateInput($order, Update::increment('totalCents', 499), refresh: Refresh::None));
```

In a transaction, use `Refresh::WithoutReadBack` to save the read when the entity doesn't need the values DynamoDB
computes. Use `Refresh::None` when the code doesn't use the entity after the write.

### How it works

- A single update asks DynamoDB to return the stored row (`ReturnValues=ALL_NEW`). That costs no extra read, so
  `Refresh::Full` and `Refresh::WithoutReadBack` behave the same.
- A transaction returns no rows. The bundle applies the fields it wrote to the entity itself, including the fields
  the normalizer added.
- DynamoDB computes the result of a nested path or an action, so the bundle cannot know it. `Refresh::Full` reads the
  entity back with a strongly consistent read after the transaction.

### Pitfalls

- With `Refresh::WithoutReadBack`, an entity updated in a transaction keeps its old values for nested paths and
  actions. After `Update::increment('totalCents', 499)`, `$order->totalCents` still holds the old total.
- The entity is brought up to date after DynamoDB has stored the write. If the stored row doesn't deserialize, for
  example because it lacks a field that has since become required, or reading it back fails, the call throws an
  `EntityOutOfSyncException` although the update is stored. Retrying it repeats the write, so an `increment()` counts
  twice. `Refresh::None` skips that step. See [Exceptions](exceptions.md#stored-data-that-doesnt-match-the-entity).

## Conditional writes

Every write input takes a condition, built with the same `Filter` as a search. DynamoDB checks the condition against
the stored row and refuses the write if it doesn't hold. An update always checks that the row exists, and adds its
own condition to that check.

```php
use Shopware\DynamodbDalBundle\Client\Input\DeleteInput;
use Shopware\DynamodbDalBundle\Client\Input\PutInput;
use Shopware\DynamodbDalBundle\Expression\Filter;

// Create the order, but never overwrite an existing one
$this->client->put(new PutInput($order, Filter::notExists('id')));

// Only pay an open order
$this->client->update(new UpdateInput(
    $order,
    ['status' => OrderStatus::Paid],
    Filter::equals('status', OrderStatus::Open),
));

// Only delete a cancelled order
$this->client->delete(new DeleteInput($order, Filter::equals('status', OrderStatus::Cancelled)));
```

A write whose condition fails throws AsyncAws's `ConditionalCheckFailedException`. In a [transaction](#transactions),
a failed condition cancels the whole transaction instead.

For optimistic locking, add a version field to the entity and require the version you read:

```php
#[Field]
public int $version = 0;
```

```php
use AsyncAws\DynamoDb\Exception\ConditionalCheckFailedException;

try {
    $this->client->update(new UpdateInput(
        $order,
        ['status' => OrderStatus::Paid, 'version' => $order->version + 1],
        Filter::equals('version', $order->version),
    ));
} catch (ConditionalCheckFailedException) {
    // The order changed after it was read: reload it and try again, or report a conflict.
}
```

### Pitfalls

- Where no row is stored, DynamoDB checks a put's or a delete's condition against a row without attributes. A
  comparison is then false, and its negation true. The delete of a cancelled order above therefore throws for an
  order that doesn't exist, although a delete without a condition would not.
- A condition built from optional criteria can end up checking nothing, such as a `Filter::and()` whose criteria are
  all `null`. As a search filter, that matches everything. As a write condition, the bundle refuses it before
  sending, because DynamoDB would apply the write unconditionally. To write without a condition, pass none.

## Batches

`batchWrite()` writes many puts and deletes, across entity classes, with `BatchWriteItem`. A batch cannot be
conditional, so a `BatchWriteInput` takes entities and keys rather than inputs with a condition: the entities to put,
and the keys or entities to delete.

```php
use Shopware\DynamodbDalBundle\Client\Input\BatchWriteInput;

$this->client->batchWrite(
    new BatchWriteInput(
        puts: $imported,
        deletes: [new Key(ReservationEntity::class, $order->id), $reservation],
    ),
);
```

`withPut()` and `withDelete()` return a new batch with more entities or keys added, to build one up. For conditions,
or to write all or nothing, use a [transaction](#transactions).

A batch names each key once, as a put or as a delete. The bundle refuses a batch that names a key twice before it
sends any of it.

### How it works

- The bundle sends every put before every delete, whatever order they were added in.
- It sends 25 operations per request, however many entity classes they span. It sends the operations that DynamoDB
  leaves unprocessed again, after a pause that doubles with each round, up to a second.
- Each put that DynamoDB stored is applied back to its entity once every request is sent, as for a single put. If a
  request fails, that happens before its exception is thrown, so the entities that were written carry the values
  their normalizer generated, and the others don't.

### Pitfalls

> [!WARNING]
> A batch is not atomic. If a request fails, the requests before it stay written.

## Transactions

`transactWrite()` runs puts, updates and deletes across entity classes as a single all-or-nothing request:

```php
use Shopware\DynamodbDalBundle\Client\Input\TransactWriteInput;

$this->client->transactWrite(
    new TransactWriteInput(
        new UpdateInput($order, ['status' => OrderStatus::Cancelled], Filter::equals('status', OrderStatus::Open)),
        new DeleteInput(new Key(ReservationEntity::class, $order->id)),
        new PutInput($event),
    ),
);
```

The operations are sent in the order they are given. `with()` returns a new transaction with more operations added
after them, to build one up.

A transaction names each key once, whatever operations name it. The bundle refuses a transaction that names a key
twice before it sends any of it.

A cancelled transaction throws `TransactionCanceledException`. Its `getCancellationReasons()` holds one reason per
operation, in the order the operations were given. Each reason tells whether its operation failed, and why.

### How it works

- If DynamoDB cancels a transaction only because another transaction conflicted with it or because it was throttled,
  the bundle sends it again after a pause that doubles each time, for up to three attempts in total. Any other
  cancellation, such as a failed condition, is thrown right away.
- Each attempt carries an idempotency token of its own (`ClientRequestToken`). If AsyncAws sends an attempt again, for
  example after a timeout, DynamoDB applies it only once.
- Once every transaction is sent, the puts and the updates keyed by an entity are applied to their entities, as
  [Keeping the entity in sync](#keeping-the-entity-in-sync) describes. If a transaction fails, the entities of the
  ones before it are brought up to date before its exception is thrown.

### Pitfalls

> [!WARNING]
> DynamoDB allows up to 100 operations per transaction. The bundle splits a larger input into several transactions of
> 100 operations, and each of them is atomic only on its own. If a later one fails, the earlier ones stay written.
> Their entities are brought up to date. The entities of the failed transaction and of the ones after it keep their
> old values.

- A transaction costs twice the write capacity of the same writes sent on their own or in a batch.
