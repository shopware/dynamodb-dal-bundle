# DynamoDB DAL Bundle

A data abstraction layer for DynamoDB, as a Symfony bundle.

Entities are plain PHP classes annotated with `#[Table]` and `#[Field]`. The bundle compiles them into definitions
when the container is built, then reads and writes them through [AsyncAws](https://async-aws.com). You work
with entities and PHP values; DynamoDB's attribute format stays inside the bundle.

- Typed fields: strings, numbers, booleans, dates, backed enums, Uids, lists and maps, and types of your own
- Get, query, scan and count, with one `Filter` builder for key conditions, filters and write conditions
- Puts, partial and nested updates, deletes and transactions, batched and retried for you
- Opaque, URL-safe pagination tokens that page forward and backward
- A definition dump, schema baselines for CI and a profiler panel

## Getting started

The [quick setup](docs/QUICK_SETUP.md) covers the requirements, installation, a first entity and how to read and
write it.

## Documentation

- [Quick setup](docs/QUICK_SETUP.md): requirements, installation, a first entity and its use
- [Basics](docs/examples/basics.md): entities, reading by key, queries, scans, counts, puts and deletes
- [Updates, conditions and transactions](docs/examples/writes.md)
- [Paginated listing](docs/examples/paginated-listing.md): previous and next links, page numbers, and pages
  merged from several queries
- [Custom types, normalizers and filters](docs/examples/extending.md)
- [Architecture decisions](docs/adr/)

## Development tooling

In the `dev` environment, the bundle adds three console commands:

| Command | Output |
|---|---|
| `dal:definition [entity]` | The compiled definition of an entity: its table, keys and indexes, and every field's type, nullability, default and serializer |
| `dal:baseline:required-fields` | JSON listing each entity's required fields. Commit it and diff it in CI to catch a field becoming required while stored rows may lack it |
| `dal:baseline:table-schema` | JSON with the key and index schema of the live tables, for the same kind of check |

With `symfony/web-profiler-bundle` installed, the profiler gets a DynamoDB panel. It lists each DynamoDB call a
request made, with its caller and duration, and the serializer timings. The panel reads the calls from Symfony's
traced HTTP client, so AsyncAws has to send them through a client named `aws.base-client`:

```yaml
framework:
    http_client:
        scoped_clients:
            aws.base-client:
                scope: '.*'

async_aws:
    http_client: aws.base-client
```

Setting `async_aws.http_client` turns off the retrying HTTP client that AsyncAws builds by default. To keep
retries, wrap `aws.base-client` with `AsyncAws\Core\HttpClient\AwsHttpClientFactory::createRetryableClient()`.

## Contributing

Read the [guidelines](docs/GUIDELINES.md) before opening a pull request.

```bash
docker compose up -d   # DynamoDB Local on port 8345; point DYNAMODB_ENDPOINT elsewhere to use another
composer phpunit       # the integration suite fails when no DynamoDB answers
composer phpstan
composer ecs
```
