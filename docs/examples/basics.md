# Basics

- [Basics](#basics)
  - [The entity](#the-entity)
  - [Reading by key](#reading-by-key)
  - [Querying](#querying)
  - [Scanning and counting](#scanning-and-counting)
  - [Reading a result](#reading-a-result)
  - [Putting and deleting](#putting-and-deleting)

This example stores a shop's orders. Each customer's orders share one partition, and an index lists orders
by status. Every snippet runs in a service that has the `Client` injected:

```php
use Shopware\DynamodbDalBundle\Client\Client;

public function __construct(
    private readonly Client $client,
) {
}
```

Every input names the entity class it works on, so no call takes one: an entity is one, a `Key` names its class,
and a search takes it as its first argument.

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

- `#[Field]` makes a property a field. The property has to be typed, and `public` or `protected`.
- Every key named in `#[Table]` or an `IndexSchema` has to be a field. The table's own key fields must not
  be nullable.
- DynamoDB has no null, so a `null` value is not stored. When a stored row lacks a field, the entity gets
  the field's default. If the field has no default and is not nullable, the read fails with
  `FieldMissingDeserializedValueException`. A field you add to an entity that already has rows therefore
  needs a default or a nullable type. `dal:baseline:required-fields` catches this in CI.
- `list<T>` fields are stored as DynamoDB lists and `array<string, T>` fields as maps. Filters and updates
  can reach into them by path, such as `meta.carrier` or `tags[0]`.
- `AbstractEntity` has a final constructor without arguments, because the bundle creates entities
  when it reads them. Add a static factory if you want one.

## Reading by key

```php
use Shopware\DynamodbDalBundle\Client\Key;

$order = $this->client->find(new Key(OrderEntity::class, 'c-42', 'o-1001')); // ?OrderEntity

$found = $this->client->findMany([
    new Key(OrderEntity::class, 'c-42', 'o-1001'),
    new Key(OrderEntity::class, 'c-42', 'o-1002'),
    new Key(CustomerEntity::class, 'c-42'),
])->grouped();

$orders = $found[OrderEntity::class] ?? [];
$customer = $found[CustomerEntity::class][0] ?? null;
```

A `Key` holds the entity class, the partition key value and, for a table with a sort key, the sort key value.
The class names the table, so one call can read keys of several entity classes. Pass the values as PHP values,
such as an enum case or a `DateTimeImmutable`; the bundle serializes them the same way it serializes the entity's
fields.

- `find()` reads one key with `GetItem`, and returns the entity or `null`.
- `findMany()` reads several with `BatchGetItem`, 100 keys per request, and requests the keys DynamoDB leaves
  unprocessed again. It returns a `GetOutput`, which streams its results as a search does, see
  [Reading a result](#reading-a-result). They come in no particular order, and keys without a row are left out.
- `grouped()` returns every found entity, keyed by class, `forEntity()` those of one class, and `toArray()` all of
  them as a list. An output can be read once, so take everything you need from one of them.
- `consistentRead: true` makes either read strongly consistent.
- `findMany()` is shorthand for `get()` with a `GetInput`, which `withKey()` and `withConsistentRead()` build up.

`refresh()` reads entities you already hold again, by their own key, and writes the stored values back into
the same instances. An entity whose row no longer exists is left as it is.

```php
use Shopware\DynamodbDalBundle\Client\Input\RefreshInput;

$this->client->refresh(new RefreshInput([$order], consistentRead: true));
```

## Querying

A query reads one partition of the table or of an index. Its key condition requires the partition key to
equal a value, and it may narrow the sort key.

```php
use App\Entity\OrderStatus;
use Shopware\DynamodbDalBundle\Client\Input\QueryInput;
use Shopware\DynamodbDalBundle\Expression\Filter;

// All orders of one customer, in sort key order
$orders = $this->client->search(new QueryInput(
    OrderEntity::class,
    Filter::equals('customerId', 'c-42'),
))->toArray();

// Paid orders of the last 30 days, newest first, from the index
$orders = $this->client->search(new QueryInput(
    OrderEntity::class,
    Filter::and(
        Filter::equals('status', OrderStatus::Paid),
        Filter::greaterThanOrEquals('createdAt', new \DateTimeImmutable('-30 days')),
    ),
    index: 'statusCreatedAtIndex',
    forward: false,
))->toArray();
```

On the sort key, a key condition accepts `equals`, `lessThan`, `lessThanOrEquals`, `greaterThan`,
`greaterThanOrEquals`, `between` and `beginsWith`. Combine it with the partition key using `Filter::and()`.
This restriction comes from DynamoDB. Any other criterion goes into `filter:`. DynamoDB applies the filter
after it has read the items, so a filter reduces the data returned but not the read capacity consumed.

```php
$orders = $this->client->search(new QueryInput(
    OrderEntity::class,
    Filter::equals('customerId', 'c-42'),
    filter: Filter::and(
        Filter::greaterThan('totalCents', 10_000),
        Filter::contains('tags', 'gift'),
        Filter::exists('meta.carrier'),
    ),
))->toArray();
```

- Values are PHP values, serialized by the field's serializer. `null` is refused with
  `NullOperandException`; to match a missing attribute, use `Filter::notExists()`.
- `Filter` also provides `equalsAny` (DynamoDB's `IN`), `sizeEquals`, `or`, `not`, `notEquals`,
  `notEqualsAny`, `notContains` and `notExists`.
- `consistentRead: true` only works on a base-table query. DynamoDB rejects it on a global secondary index.
- A key condition that checks nothing, such as `Filter::equalsAny()` without values, throws
  `ConditionEmptyException` before the query is sent. As a `filter:`, it matches everything.

`Filter::and()` without arguments matches everything, and `->with()` returns it with criteria added, leaving
the filter it was called on as it is. That makes it suitable for criteria from a search form:

```php
$filter = Filter::and();
if ($criteria->minTotalCents !== null) {
    $filter = $filter->with(Filter::greaterThanOrEquals('totalCents', $criteria->minTotalCents));
}
if ($criteria->tag !== null) {
    $filter = $filter->with(Filter::contains('tags', $criteria->tag));
}

$result = $this->client->search(new QueryInput(
    OrderEntity::class,
    Filter::equals('customerId', 'c-42'),
    filter: $filter,
));
```

## Scanning and counting

A scan reads the whole table. Use it for background jobs, not for requests that need to respond quickly.

```php
use Shopware\DynamodbDalBundle\Client\Input\ScanInput;

foreach ($this->client->search(new ScanInput(OrderEntity::class, Filter::equals('status', OrderStatus::Open))) as $order) {
    // …
}
```

`count()` takes the same inputs and counts matches with `Select=COUNT` across all pages, ignoring `limit`.
No items are transferred, but DynamoDB still reads every item the query or scan covers.

```php
$open = $this->client->count(new QueryInput(
    OrderEntity::class,
    Filter::equals('status', OrderStatus::Open),
    index: 'statusCreatedAtIndex',
));
```

## Reading a result

`search()` returns a `SearchOutput` that streams its matches. It fetches DynamoDB's pages as you iterate, so
even a large scan never has to fit in memory. `findMany()` and `get()` return a `GetOutput` that streams the same
way, one `BatchGetItem` of 100 keys at a time. Nothing is read until the output is, and that is also when a key,
a query or a request fails. An output can be read once, in one of these ways:

- `foreach` streams the matches
- `toArray()` returns the matches as a list
- `first()` returns the first match, or `null`
- `page()`, on a search, returns the matches with tokens for the next and previous page. See
  [Paginated listing](paginated-listing.md).
- `forEntity()` and `grouped()`, on a key read, return the entities of one class, or all of them by class

With a `limit` on the input, each of these returns at most that many matches and stops reading DynamoDB's
pages once it has them. A second read throws a `LogicException`; run the search or key read again instead.

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

$this->client->delete(new DeleteInput($order));
$this->client->delete(new DeleteInput(new Key(OrderEntity::class, 'c-42', 'o-1002')));
```

- A put creates the item or replaces it entirely. Fields that are `null` are left out of the item.
- An uninitialized required field fails the put with `FieldMissingSerializedValueException`
  before anything is sent to DynamoDB.
- A normalizer can generate values during a put, such as an ID or a timestamp. These are written back to the
  entity afterwards. See [A normalizer](extending.md#a-normalizer).
- Deleting an item that does not exist is not an error.
- `put()` and `delete()` write one item each. To write many, change only some fields, add conditions or write
  atomically, see [Updates, conditions and transactions](writes.md).
