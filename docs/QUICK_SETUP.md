# Quick setup

- [Quick setup](#quick-setup)
  - [Requirements](#requirements)
  - [Installation](#installation)
  - [Defining an entity](#defining-an-entity)
  - [Usage](#usage)

## Requirements

- PHP 8.4 or later
- Symfony 7.3 or later
- An `AsyncAws\DynamoDb\DynamoDbClient` service

## Installation

```bash
composer require shopware/dynamodb-dal-bundle async-aws/async-aws-bundle
```

Register both bundles in `config/bundles.php` if Symfony Flex did not:

```php
return [
    // ...
    AsyncAws\Symfony\Bundle\AsyncAwsBundle::class => ['all' => true],
    Shopware\DynamodbDalBundle\ShopwareDynamodbDalBundle::class => ['all' => true],
];
```

The AsyncAws bundle provides the `DynamoDbClient`:

```yaml
# config/packages/async_aws.yaml
async_aws:
    clients:
        dynamo_db:
            config:
                region: '%env(AWS_REGION)%'
                # endpoint: 'http://localhost:8000' # DynamoDB Local
```

Without the AsyncAws bundle, register a service with the ID `AsyncAws\DynamoDb\DynamoDbClient` yourself.

## Defining an entity

```php
namespace App\Entity;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Attribute\Field;
use Shopware\DynamodbDalBundle\Attribute\Table;

#[Table(name: 'order', hashKey: 'customerId', rangeKey: 'id')]
class OrderEntity extends AbstractEntity
{
    #[Field]
    public string $customerId;

    #[Field]
    public string $id;

    #[Field]
    public int $totalCents;

    #[Field]
    public ?string $note = null;
}
```

List every entity in the bundle configuration, together with the table its items live in:

```yaml
# config/packages/shopware_dynamodb_dal.yaml
shopware_dynamodb_dal:
    entities:
        App\Entity\OrderEntity: '%env(DYNAMODB_TABLE_ORDER)%'
```

`#[Table(name: ...)]` is the entity's logical name, which the `EntityDefinitionRegistry` and the console commands
know it by. The configured value is the physical table name. The bundle does not create tables. Create them
with the keys and indexes the entity declares, using your infrastructure tooling.

A mistake in an entity's declaration fails the container build. Examples are a key that is not a field, or a
property type that no serializer supports.

| PHP property type | Stored as |
|---|---|
| `string` | `S` |
| `int`, `float` | `N` |
| `bool` | `BOOL` |
| `DateTimeImmutable`, `DateTime` | `N`, a Unix timestamp in whole seconds, read back in UTC |
| Backed enum | `S`, the case's value |
| `Uuid`, `Ulid` and other Symfony Uids (needs `symfony/uid`) | `S` |
| `array` with `@var list<T>` | `L` |
| `array` with `@var array<string, T>` | `M` |
| `array` without a `@var` type | `S`, as JSON |
| Any other class implementing `JsonSerializable` | `S`, as JSON. Reads back as an array, see [`JsonSerializable` value objects](examples/extending.md#a-jsonserializable-value-object) |

List and map values can be any type from this table, including nested lists and maps. Add types of your own
with a [field serializer](examples/extending.md#a-field-type-of-your-own).

## Usage

Inject the `Client` and the `EntityDefinitionRegistry`:

```php
use App\Entity\OrderEntity;
use Shopware\DynamodbDalBundle\Client\Client;
use Shopware\DynamodbDalBundle\Client\Index;
use Shopware\DynamodbDalBundle\Client\Input\GetInput;
use Shopware\DynamodbDalBundle\Client\Input\PutInput;
use Shopware\DynamodbDalBundle\Client\Input\QueryInput;
use Shopware\DynamodbDalBundle\Definition\EntityDefinitionRegistry;
use Shopware\DynamodbDalBundle\Expression\Filter;

final readonly class OrderRepository
{
    public function __construct(
        private Client $client,
        private EntityDefinitionRegistry $definitions,
    ) {
    }

    public function save(OrderEntity $order): void
    {
        $this->client->put($this->definitions->getByEntityClass(OrderEntity::class), new PutInput($order));
    }

    public function find(string $customerId, string $id): ?OrderEntity
    {
        return $this->client->get(new GetInput([OrderEntity::class => [new Index($customerId, $id)]]))->first();
    }

    /**
     * @return list<OrderEntity>
     */
    public function forCustomer(string $customerId): array
    {
        return $this->client->search(
            $this->definitions->getByEntityClass(OrderEntity::class),
            new QueryInput(Filter::equals('customerId', $customerId)),
        )->toArray();
    }
}
```

Next, [Basics](examples/basics.md) covers reading by key, queries, scans, counts, puts and deletes.
