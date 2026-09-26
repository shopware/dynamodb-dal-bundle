# Testing code that uses the bundle

- [Testing code that uses the bundle](#testing-code-that-uses-the-bundle)
  - [A double of the client](#a-double-of-the-client)
  - [Results of a double](#results-of-a-double)
  - [Exceptions of a double](#exceptions-of-a-double)
  - [A filter or update action of your own](#a-filter-or-update-action-of-your-own)
  - [A normalizer](#a-normalizer)

A unit test of code that reads and writes through the `Client` needs no DynamoDB. The `Client` can be doubled, and
the bundle's `Test` namespace builds what a double returns or throws, and what a filter, update action or normalizer
of your own is compiled against. These snippets build on the `OrderEntity` from [Basics](basics.md).

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

Inputs, keys and filters are plain values with public properties, so a test builds real ones and compares them,
such as `$query->keyCondition instanceof EqualsFilter`, rather than doubling them.

## Results of a double

The outputs of a read can be doubled too, but a real one is closer to what the code under test receives.
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

- `OutputFactory::search()` streams the entities as the search would find them, limited and paged as the input says.
  A page's tokens need the key attributes of each entity, which `$keyOf` gives. See
  [Testing a listing](paginated-listing.md#testing-a-listing).
- `OutputFactory::get()` stands in for `get()` and `findMany()`.
- Outputs stream once, as the real ones do, so a double builds a fresh one per call.
- `find()` returns the entity itself, so a double of it needs no output.

## Exceptions of a double

A failed write throws the DynamoDB exceptions it gets. `ExceptionFactory` builds them, as DynamoDB reports them:

```php
use Shopware\DynamodbDalBundle\Test\ExceptionFactory;

$client->method('put')->willThrowException(ExceptionFactory::conditionalCheckFailed());

// One reason per operation, in the order the transaction was given them
$client->method('transactWrite')->willThrowException(ExceptionFactory::transactionCanceled('None', 'ConditionalCheckFailed'));
```

The exceptions of the bundle itself name the definition, and the field, they are about. `EntityDefinitionFactory`
builds the definition of an entity class:

```php
use Shopware\DynamodbDalBundle\Exception\ConditionEmptyException;
use Shopware\DynamodbDalBundle\Exception\WrongTypeException;
use Shopware\DynamodbDalBundle\Test\EntityDefinitionFactory;

$definition = EntityDefinitionFactory::create(OrderEntity::class);

$client->method('put')->willThrowException(new ConditionEmptyException($definition));
$client->method('update')->willThrowException(new WrongTypeException($definition->getFieldDefinition('status'), 'string', 1));
```

## A filter or update action of your own

A [filter](extending.md#a-filter-of-your-own) or [update action](extending.md#an-update-action-of-your-own) of your
own compiles against the definition of an entity. `CompiledExpression` compiles it as a request sends it, and
`resolved()` reads it back with every placeholder replaced by the name or value it stands for:

```php
use Shopware\DynamodbDalBundle\Test\CompiledExpression;
use Shopware\DynamodbDalBundle\Test\EntityDefinitionFactory;

$definition = EntityDefinitionFactory::create(OrderEntity::class);

static::assertSame(
    'size(tags) > 2',
    CompiledExpression::ofFilter($definition, new SizeGreaterThanFilter('tags', 2))->resolved(),
);
static::assertSame(
    'SET meta.previousStatus = status',
    CompiledExpression::ofUpdate($definition, new CopyAction('status', 'meta.previousStatus'))->resolved(),
);
```

- `EntityDefinitionFactory::create()` builds the definition from the entity's attributes, as the container does, and
  refuses a wrongly declared entity the same way. It takes field serializers of your own, which take precedence as
  they do when registered, the normalizer `#[Table]` names where it needs constructor arguments, and the physical
  table name.
- `resolved()` quotes a string, reads a number, a boolean or `null` as it is, and writes a list as `[…]`, a map as
  `{"key": …}` and a set as `<<…>>`. The raw `expression`, `names` and `values` are there as well.
- `ofUpdate()` passes the update through the entity's normalizer first, as a write does, and throws
  `UpdateEmptyException` for an update that writes nothing.

## A normalizer

A normalizer works on a `NormalizerContext`, which `NormalizerContext::fromFields()` builds. See
[A normalizer](extending.md#a-normalizer).
