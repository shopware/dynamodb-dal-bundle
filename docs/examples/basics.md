# Basics

- [Basics](#basics)
  - [The entity](#the-entity)
  - [Reading by key](#reading-by-key)
  - [Querying](#querying)
  - [Reading a result](#reading-a-result)
  - [Scanning and counting](#scanning-and-counting)
  - [Filters](#filters)
  - [Putting and deleting](#putting-and-deleting)

This example stores a shop's orders. Each customer's orders share one partition, and an index lists orders by
status. Every snippet runs in a service that has the `Client` injected:

```php
use Shopware\DynamodbDalBundle\Client\Client;

public function __construct(
    private readonly Client $client,
) {
}
```

No `Client` method takes the entity class as an argument of its own. Every input carries it: an entity is an instance
of its class, a `Key` names its class, and a search takes the class as its first argument.

[Exceptions](exceptions.md) lists what each call can throw.

## The entity

```php
namespace App\Entity;

enum OrderStatus: string
{
    case Open = 'open';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
}
```

```php
namespace App\Entity;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Attribute\Field;
use Shopware\DynamodbDalBundle\Attribute\Table;
use Shopware\DynamodbDalBundle\Definition\IndexSchema;

#[Table(
    name: 'order',
    hashKey: 'customerId',
    rangeKey: 'id',
    indexes: [new IndexSchema('statusCreatedAtIndex', hashKey: 'status', rangeKey: 'createdAt')],
)]
class OrderEntity extends AbstractEntity
{
    #[Field]
    public string $customerId;

    #[Field]
    public string $id;

    #[Field]
    public OrderStatus $status = OrderStatus::Open;

    #[Field]
    public \DateTimeImmutable $createdAt;

    #[Field]
    public ?\DateTimeImmutable $updatedAt = null;

    #[Field]
    public int $totalCents;

    #[Field]
    public ?string $note = null;

    /**
     * @var list<string>
     */
    #[Field]
    public array $tags = [];

    /**
     * @var array<string, string>
     */
    #[Field]
    public array $meta = [];
}
```

```yaml
# config/packages/shopware_dynamodb_dal.yaml
shopware_dynamodb_dal:
    entities:
        App\Entity\OrderEntity: '%env(DYNAMODB_TABLE_ORDER)%'
```

`#[Field]` makes a property a field. The property has to be typed, and `public` or `protected`. It can be neither
`readonly` nor `private(set)`, because the bundle assigns the fields when it reads an entity.

Every key named in `#[Table]` or an `IndexSchema` has to be a field. The table's own key fields must not be nullable.
A table holds the entities of one class only.

`list<T>` fields are stored as DynamoDB lists, and `array<string, T>` fields as maps. Filters and updates can reach
into them by path, such as `meta.carrier` or `tags[0]`. [Quick setup](../QUICK_SETUP.md#defining-an-entity) lists how
every PHP type is stored.

`AbstractEntity` has a final constructor without arguments, because the bundle creates entities when it reads them.
Add a static factory if you want one.

### How it works

- A `null` value is not stored as DynamoDB's `NULL`. A put leaves the field out of the row, and an update removes it.
- When a stored row lacks a field, the entity gets `null` if the field is nullable, and the field's default
  otherwise. If the field has neither, and the entity's [normalizer](extending.md#a-normalizer) doesn't fill it in,
  the read fails.
- A field is stored under its property name.

### Pitfalls

> [!WARNING]
> A field you add to an entity whose table already has rows needs a default or a nullable type. Otherwise every read
> of an old row fails. `dal:baseline:compare` flags this as breaking, and the [baseline action](../../README.md#baseline-in-ci)
> does so on the pull request.

- A default is only filled in when the entity is read. The stored row still lacks the field, so it matches no filter
  or key condition on it, and it is missing from any index keyed on it, until a put writes the row again.
- Renaming a property renames the attribute. Old rows read the field as missing, and the next put of such an entity
  erases the value stored under the old name.
- Changing how a field is stored, such as its type or an array's `@var` docblock, makes every old row fail to read.
- An `array` without a `@var` type is stored as a JSON string, not as a list or map.
- `@var list<string>|null` and `@var list<string> $tags` fail the container build. A nullable list needs a docblock
  without `null`.
- Removing a case from an enum makes every row that stores it fail to read.
- A `DateTimeImmutable` is stored in whole seconds, and read back in UTC.
- An int-backed enum is stored as a string, so a range filter on it compares text: `10` sorts before `9`.
- Every index a search reads has to project all attributes. An index that projects `KEYS_ONLY` or `INCLUDE` returns
  rows that don't deserialize.

## Reading by key

```php
use Shopware\DynamodbDalBundle\Client\Key;

$order = $this->client->find(new Key(OrderEntity::class, 'c-42', 'o-1001')); // ?OrderEntity

// CustomerEntity is another entity, keyed by the customer ID alone
$found = $this->client->findMany([
    new Key(OrderEntity::class, 'c-42', 'o-1001'),
    new Key(OrderEntity::class, 'c-42', 'o-1002'),
    new Key(CustomerEntity::class, 'c-42'),
])->grouped();

$orders = $found[OrderEntity::class] ?? [];
$customer = $found[CustomerEntity::class][0] ?? null;
```

A `Key` holds the entity class, the hash key value and, for a table with a range key, the range key value. The
class names the table, so one call can read keys of several entity classes. Pass the values as PHP values, such as an
enum case or a `DateTimeImmutable`. The bundle serializes them the same way it serializes the entity's fields.

`find()` reads one key and returns the entity, or `null`. `findMany()` reads several keys and returns a `GetOutput`,
which streams its entities as a search does (see [Reading a result](#reading-a-result)):

| Method | Returns |
|---|---|
| `grouped()` | Every entity found, keyed by class |
| `forEntity($class)` | The entities found of one class |
| `toArray()` | Every entity found, as one list |

`consistentRead: true` makes `find()` and `findMany()` strongly consistent. `findMany()` is shorthand for `get()`
with a `GetInput`, which `withKey()` and `withConsistentRead()` build up.

`refresh()` reads entities you already hold again, by their own key, and writes the stored values into the same
instances:

```php
use Shopware\DynamodbDalBundle\Client\Input\RefreshInput;

$this->client->refresh(new RefreshInput([$order], consistentRead: true));
```

### How it works

- `find()` sends a `GetItem`.
- `findMany()` sends `BatchGetItem` requests of 100 keys each. It requests the keys that DynamoDB leaves unprocessed
  again, after a pause that doubles with each round, up to a second.
- A key given more than once is read once. Its entity comes back once, and `refresh()` writes the row into every
  instance with that key.

### Pitfalls

- An output can be read only once, so take everything you need from one call of `grouped()`, `forEntity()` or
  `toArray()`.
- `findMany()` returns the entities in no particular order, and leaves out keys without a row. To pair the entities
  with their keys, index them yourself.
- `refresh()` leaves an entity whose row no longer exists as it is, without telling you.

## Querying

A query reads one partition of the table or of an index. Its key condition, a `Filter::keyFilter()`, compares the
hash key with `equals()`, and may narrow the range key.

```php
use App\Entity\OrderStatus;
use Shopware\DynamodbDalBundle\Client\Input\QueryInput;
use Shopware\DynamodbDalBundle\Expression\Filter;

// All orders of one customer, in range key order
$orders = $this->client->search(new QueryInput(
    OrderEntity::class,
    Filter::keyFilter(Filter::equals('customerId', 'c-42')),
))->toArray();

// Paid orders of the last 30 days, newest first, from the index
$orders = $this->client->search(new QueryInput(
    OrderEntity::class,
    Filter::keyFilter(
        Filter::equals('status', OrderStatus::Paid),
        Filter::greaterThanOrEquals('createdAt', new \DateTimeImmutable('-30 days')),
    ),
    index: 'statusCreatedAtIndex',
    forward: false,
))->toArray();
```

`Filter::keyFilter()` takes the hash key as an `equals()`, and the range key, if any, as one `equals()`,
`lessThan()`, `lessThanOrEquals()`, `greaterThan()`, `greaterThanOrEquals()`, `between()` or `beginsWith()`. A range key
given as `null` drops out, for an optional criterion. That is all DynamoDB takes in a key condition, so any other
criterion goes into `filter:`:

```php
$orders = $this->client->search(new QueryInput(
    OrderEntity::class,
    Filter::keyFilter(Filter::equals('customerId', 'c-42')),
    filter: Filter::and(
        Filter::greaterThan('totalCents', 10_000),
        Filter::contains('tags', 'gift'),
        Filter::exists('meta.carrier'),
    ),
))->toArray();
```

[Filters](#filters) lists every criterion.

`index:` names an index declared in `#[Table]`. `forward: false` reads the range key in descending order. `limit:`
stops the search after that many entities.

The bundle checks the query against the key of the table or index before it sends the query. It refuses an index
that `#[Table]` doesn't declare, and a key filter whose fields are not the hash and range key of the table or
index queried.

### How it works

- DynamoDB applies `filter:` after it has read the rows. A filter reduces what the query returns, but not the read
  capacity it consumes.
- Without a filter, the bundle passes `limit` on to DynamoDB. With a filter, every request reads a full page of up to
  1 MB, because DynamoDB applies its own limit before the filter.

### Pitfalls

- A filter that matches few rows reads many full pages to fill a `limit`, and each of them costs read capacity.
- The bundle checks against the key schema that `#[Table]` declares, not against the table. An index that `#[Table]`
  declares but the table doesn't have fails at DynamoDB.
- `consistentRead: true` only works on a query of the table. DynamoDB rejects it on a global secondary index.

## Reading a result

`search()` returns a `SearchOutput`, and `findMany()` and `get()` return a `GetOutput`. Both stream their entities,
and both can be read once, in one of these ways:

| Method | Output | Returns |
|---|---|---|
| `foreach` | Both | Streams the entities |
| `toArray()` | Both | The entities as a list |
| `first()` | Both | The first entity, or `null` |
| `page()` | `SearchOutput` | The entities, with tokens for the next and previous page. See [Paginated listing](paginated-listing.md) |
| `forEntity()`, `grouped()` | `GetOutput` | The entities of one class, or all of them keyed by class |

With a `limit` on the input, each of these returns at most that many entities.

### How it works

- An output fetches DynamoDB's pages as you iterate, so even a large scan never has to fit in memory. A key read
  streams the same way, one `BatchGetItem` of 100 keys at a time.
- With a `limit`, the output stops reading DynamoDB's pages once it has enough entities.
- Nothing is read until the output is. That is also when a key, a query or a request fails.

### Pitfalls

- A second read of an output throws a `\LogicException`. Run the search or key read again instead.
- An exception of the read is thrown when the output is read, not when `search()` or `findMany()` returns. Put the
  `try` around the read. See [When a read throws](exceptions.md#when-a-read-throws).

## Scanning and counting

A scan reads the whole table. Use it for background jobs, not for requests that need to respond quickly.

```php
use Shopware\DynamodbDalBundle\Client\Input\ScanInput;

foreach ($this->client->search(new ScanInput(OrderEntity::class, Filter::equals('status', OrderStatus::Open))) as $order) {
    // …
}
```

`count()` takes the same inputs, and counts the matches with `Select=COUNT` across all pages. It ignores `limit` and
`cursor`.

```php
$open = $this->client->count(new QueryInput(
    OrderEntity::class,
    Filter::keyFilter(Filter::equals('status', OrderStatus::Open)),
    index: 'statusCreatedAtIndex',
));
```

### How it works

- A scan reads the table in one sequential pass. It can't scan an index, and it can't run in parallel segments.
- `count()` transfers no entities. DynamoDB still reads every row the query or scan covers, whether the filter
  matches it or not.

### Pitfalls

- A scan costs the read capacity of the whole table, whatever its filter matches.
- A listing that shows a page and a total reads its rows twice: once for the page, and once for `count()`.

## Filters

`Filter` builds the key condition of a query, the filter of a query or scan, and the
[condition of a write](writes.md#conditional-writes). Each method returns a filter. A key condition takes a
`keyFilter()` only, see [Querying](#querying). The values are PHP values,
serialized by the field's serializer, so `Filter::equals('status', OrderStatus::Paid)` compares with the stored
`'paid'`.

| Method | DynamoDB | Matches |
|---|---|---|
| `equals($field, $value)`, `notEquals(...)` | `=`, `NOT … =` | The value, or anything else |
| `lessThan`, `lessThanOrEquals`, `greaterThan`, `greaterThanOrEquals` | `<`, `<=`, `>`, `>=` | An ordered comparison with the value |
| `between($field, $from, $to)` | `BETWEEN … AND …` | A value in the range, both ends included |
| `equalsAny($field, [...])`, `notEqualsAny(...)` | `IN (…)`, `NOT … IN (…)` | One of the values, or none of them |
| `beginsWith($field, $prefix)` | `begins_with()` | A string that starts with the prefix |
| `contains($field, $value)`, `notContains(...)` | `contains()` | A string that contains the substring, or a list or set that contains the element |
| `containsAny($field, [...])`, `containsAll($field, [...])` | `contains() OR …`, `contains() AND …` | A field that contains any, or all, of the values |
| `exists($field)`, `notExists($field)` | `attribute_exists()`, `NOT attribute_exists()` | A field that is stored, or missing |
| `isEmpty($field)`, `isNotEmpty($field)` | `NOT attribute_exists() OR size() = 0`, `size() > 0` | A string, list, map or set that is empty or missing, or the rest |
| `and(...)`, `or(...)`, `not($filter)` | `AND`, `OR`, `NOT` | All, any, or none of the filters |
| `keyFilter($hashKey, $rangeKey)` | `… = … AND …` | A query's key condition, see [Querying](#querying) |

`null` is not a value. To match a missing attribute, use `Filter::notExists()`.

The comparisons take `Filter::size()` in place of the field, and `Filter::field()` or `Filter::size()` in place of a
value. Both sides have to be stored as the same type:

```php
Filter::lessThan(Filter::size('tags'), 10);                     // fewer than 10 tags
Filter::greaterThan('updatedAt', Filter::field('createdAt'));   // changed after it was created
```

`Filter::and()` and `Filter::or()` leave out a `null`. That makes them suitable for criteria from a search form, where
a criterion that is not given is `null`:

```php
// $criteria holds what the search form submitted
$result = $this->client->search(new QueryInput(
    OrderEntity::class,
    Filter::keyFilter(Filter::equals('customerId', 'c-42')),
    filter: Filter::and(
        $criteria->minTotalCents !== null ? Filter::greaterThanOrEquals('totalCents', $criteria->minTotalCents) : null,
        Filter::containsAny('tags', $criteria->tags), // no tags check nothing
    ),
));
```

The filters that `and()` and `or()` return have a `with()`, to add criteria to a filter built elsewhere. It leaves out
a `null` as well, and returns a new filter. The original filter stays unchanged.

### How it works

- The prefix of `beginsWith()` and the substring of `contains()` on a string are not values of the field. They are
  part of the stored string, so `Filter::beginsWith('id', '0190')` works on a `Uuid` field, and `contains()` can
  search a field stored as JSON.
- The bundle checks what a filter does with a field against the type the field is stored as. Where DynamoDB would
  reject the filter or never match it, the bundle throws before the request. Examples are `size()` of a number,
  `beginsWith()` on a list, `contains()` on a map, an ordered comparison of a list, and two operands of different
  types.
- A comparison with a missing attribute is false, and its negation is true.

### Pitfalls

- A criterion without values checks nothing and drops out. As a search filter, `Filter::equalsAny('id', [])` then
  matches every entity, not none. The same holds for `containsAny()` and `containsAll()` without values, and for an
  `and()` or `or()` whose criteria are all `null`. As a write condition, the bundle refuses such a filter instead.
- A negation matches entities that lack the attribute. `Filter::notEquals('status', …)` matches entities without a
  status, and `Filter::notEquals(Filter::size('tags'), 0)` matches entities without tags, which `isNotEmpty()` leaves
  out.
- A prefix or substring has to match the stored form. An enum is stored by its value. A JSON field escapes slashes
  and non-ASCII characters, so a `contains()` of `'ü'` never matches a stored `ü`.
- DynamoDB limits `equalsAny()` to 100 values, and an expression to 4 KB and 300 operators. `containsAny()` and
  `containsAll()` expand to one `contains()` per value, so they reach those limits sooner, at around 120 values on a
  short field name. A filter past the limits fails at DynamoDB.

## Putting and deleting

```php
use Shopware\DynamodbDalBundle\Client\Input\DeleteInput;
use Shopware\DynamodbDalBundle\Client\Input\PutInput;
use Shopware\DynamodbDalBundle\Client\Key;

$order = new OrderEntity();
$order->customerId = 'c-42';
$order->id = 'o-1001';
$order->createdAt = new \DateTimeImmutable();
$order->totalCents = 4_990;

$this->client->put(new PutInput($order));

// Only where the order isn't stored yet
$created = $this->client->insert($order);

$this->client->delete(new DeleteInput($order));
$this->client->delete(new DeleteInput(new Key(OrderEntity::class, 'c-42', 'o-1002')));
```

A put writes the entity as a whole: it creates the row, or replaces the stored row entirely. Every field that is
neither nullable nor has a default has to be set before the put. An insert writes the entity like a put, but only
where no row is stored under its key. Otherwise it writes nothing and returns `false`. It takes the entity or an
`InsertInput`. A delete takes the entity or its `Key`, and returns `false` where no row is stored.

`put()`, `insert()` and `delete()` write one entity each. To write many, change only some fields, add conditions or
write atomically, see [Updates, conditions and transactions](writes.md).

### How it works

- Fields that are `null` are left out of the row.
- A normalizer can generate values during a put or an insert, such as an ID or a timestamp. The bundle writes them
  back to the entity afterwards. See [A normalizer](extending.md#a-normalizer).
- Deleting an entity that doesn't exist is not an error.

### Pitfalls

- A put erases every attribute of the row that the entity doesn't declare. The bundle skips such an attribute when it
  reads the row, and the next put leaves it out. During a rolling deploy, an older instance that reads and puts an
  entity erases the fields a newer version added.
- Two writers that each read an entity, change it and put it overwrite each other's changes. Use an
  [update](writes.md#partial-updates), which writes only the fields it names, or a
  [condition](writes.md#conditional-writes).
