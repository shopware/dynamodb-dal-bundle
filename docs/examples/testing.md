# Testing code that uses the bundle

- [Testing code that uses the bundle](#testing-code-that-uses-the-bundle)
  - [A double of the client](#a-double-of-the-client)
  - [Results of a double](#results-of-a-double)
  - [Exceptions of a double](#exceptions-of-a-double)
  - [A filter or update action of your own](#a-filter-or-update-action-of-your-own)
  - [A normalizer](#a-normalizer)

A unit test of code that reads and writes through the `Client` needs no DynamoDB. The `Client` can be doubled. The
bundle's `Test` namespace builds what a double returns or throws, and what a filter, update action or normalizer of
your own is compiled against. These snippets build on the `OrderEntity` from [Basics](basics.md).

## A double of the client

The `Client` is not `final`, so PHPUnit doubles it like any other service. Each call gets its input alone, and the
input names the entity class it works on:

```php
use Shopware\DynamodbDalBundle\Client\Client;
use Shopware\DynamodbDalBundle\Client\Input\PutInput;

$client = $this->createMock(Client::class);
$client->expects(static::once())
    ->method('put')
    ->with(static::callback(static fn (PutInput $input): bool => $input->class === OrderEntity::class
        && $input->entity === $order));

new OrderRepository($client)->save($order);
```

Inputs, keys and filters are plain values with public properties. A test builds real ones and compares them, rather
than doubling them. Build the filter to compare with through `Filter`, as the code under test does, so the test
doesn't depend on the classes a filter is made of:

```php
static::assertEquals(Filter::keyFilter(Filter::equals('customerId', 'c-42')), $query->keyCondition);
```

## Results of a double

The outputs of a read can be doubled too, but a real output is closer to what the code under test receives.
`OutputFactory` builds them:

```php
use Shopware\DynamodbDalBundle\Client\Input\QueryInput;
use Shopware\DynamodbDalBundle\Client\Output\GetOutput;
use Shopware\DynamodbDalBundle\Client\Output\SearchOutput;
use Shopware\DynamodbDalBundle\Test\OutputFactory;

$client->method('search')->willReturnCallback(
    static fn (QueryInput $query): SearchOutput => OutputFactory::search($query, [$first, $second]),
);
$client->method('findMany')->willReturnCallback(
    static fn (array $keys): GetOutput => OutputFactory::get([$first]),
);
```

| Method | Stands in for | Returns |
|---|---|---|
| `OutputFactory::search($input, $entities, $keyOf)` | `search()` | A `SearchOutput` that streams the entities, limited and paged as the input says |
| `OutputFactory::get($entities)` | `get()` and `findMany()` | A `GetOutput` that streams the entities |

`find()` returns the entity itself, so a double of it needs no output. A page's tokens need the key attributes of
each entity, which `$keyOf` gives. See [Testing a listing](paginated-listing.md#testing-a-listing).

### Pitfalls

- Outputs stream once, as the real ones do. Build a fresh output per call with `willReturnCallback()`. A
  `willReturn()` hands the same output to every call, and the second read throws.
- `OutputFactory::search()` doesn't evaluate the key condition or the filter. It streams exactly the entities it is
  given.

## Exceptions of a double

A failed write throws the DynamoDB exceptions it gets. `ExceptionFactory` builds them, as DynamoDB reports them:

```php
use Shopware\DynamodbDalBundle\Test\ExceptionFactory;

$client->method('put')->willThrowException(ExceptionFactory::conditionalCheckFailed());

// One reason per operation, in the order the transaction was given them
$client->method('transactWrite')->willThrowException(ExceptionFactory::transactionCanceled('None', 'ConditionalCheckFailed'));
```

The exceptions of the bundle itself name the definition they are about, and the field where there is one.
`EntityDefinitionFactory` builds the definition of an entity class:

```php
use Shopware\DynamodbDalBundle\Exception\ConditionEmptyException;
use Shopware\DynamodbDalBundle\Exception\WrongTypeException;
use Shopware\DynamodbDalBundle\Test\EntityDefinitionFactory;

$definition = EntityDefinitionFactory::create(OrderEntity::class);

$client->method('put')->willThrowException(new ConditionEmptyException($definition));
$client->method('update')->willThrowException(new WrongTypeException($definition->getFieldDefinition('status'), 'string', 1));
```

[Exceptions](exceptions.md) lists what each exception means.

## A filter or update action of your own

A [filter](extending.md#a-filter-of-your-own) or [update action](extending.md#an-update-action-of-your-own) of your
own compiles against the definition of an entity. `CompiledExpression` compiles it as a request sends it, and
`resolved()` reads it back with every placeholder replaced by the name or value it stands for:

```php
use Shopware\DynamodbDalBundle\Definition\AttributeType;
use Shopware\DynamodbDalBundle\Test\CompiledExpression;
use Shopware\DynamodbDalBundle\Test\EntityDefinitionFactory;

$definition = EntityDefinitionFactory::create(OrderEntity::class);

static::assertSame(
    'attribute_type(totalCents, "S")',
    CompiledExpression::ofFilter($definition, new StoredAsFilter('totalCents', AttributeType::String))->resolved(),
);
static::assertSame(
    'SET meta.previousStatus = status',
    CompiledExpression::ofUpdate($definition, new CopyAction('status', 'meta.previousStatus'))->resolved(),
);
```

`resolved()` quotes a string, and writes a number, a boolean or `null` as it is. It writes a list as `[…]`, a map as
`{"key": …}` and a set as `<<…>>`. The raw `expression`, `names` and `values` are there as well.

`EntityDefinitionFactory::create()` also takes field serializers of your own, which take precedence as they do when
registered, the normalizer that `#[Table]` names, and the physical table name.

### How it works

- `EntityDefinitionFactory::create()` builds the definition from the entity's attributes, as the container does, and
  refuses a wrongly declared entity the same way.
- `ofUpdate()` passes the update through the entity's normalizer first, as a write does. It throws
  `UpdateEmptyException` for an update that writes nothing.

### Pitfalls

- `EntityDefinitionFactory::create()` builds the normalizer that `#[Table]` names without constructor arguments. Pass
  a normalizer that needs arguments in yourself, or the factory throws a `\LogicException`.

## A normalizer

A [normalizer](extending.md#a-normalizer) works on a `NormalizerContext`. `NormalizerContext::fromFields()` builds
one for the operation and fields you give it. Run the normalizer on it, and read the fields back:

```php
use Shopware\DynamodbDalBundle\Serializer\NormalizerContext;
use Shopware\DynamodbDalBundle\Serializer\NormalizerOperation;

$context = NormalizerContext::fromFields(NormalizerOperation::Update, [
    'amountCents' => -1_300,
    'createdAt' => new \DateTimeImmutable('2026-01-01'),
]);

new LedgerEntryNormalizer()->normalize($context);

static::assertInstanceOf(\DateTimeImmutable::class, $context->get('updatedAt'));
static::assertFalse($context->has('createdAt'));
```

`fromFields()` takes the fields as given, and doesn't check them against the entity. Pass the fields the operation
carries: every field for a `Put` or `Read`, the written paths for an `Update`, and the key fields for a `Key`.
