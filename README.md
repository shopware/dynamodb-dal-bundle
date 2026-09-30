# DynamoDB DAL Bundle

A data abstraction layer for DynamoDB, as a Symfony bundle.

Entities are plain PHP classes annotated with `#[Table]` and `#[Field]`. The bundle compiles them into definitions
when the container is built, then reads and writes them through [AsyncAws](https://async-aws.com). You work
with entities and PHP values; DynamoDB's attribute format stays inside the bundle.

- Typed fields: strings, numbers, booleans, dates, backed enums, Uids, lists and maps, and types of your own
- Get, query, scan and count, with one `Filter` builder for key conditions, filters and write conditions
- Puts, inserts, partial and nested updates, atomic counters and appends, and deletes, one at a time, in batches or
  in transactions, chunked and retried for you
- Opaque, URL-safe pagination tokens that page forward and backward
- Console commands that print a definition, check it against its live table and record a baseline for CI, and a
  profiler panel

## Getting started

The [quick setup](docs/QUICK_SETUP.md) covers the requirements, installation, a first entity and how to read and
write it.

## Documentation

- [Quick setup](docs/QUICK_SETUP.md): requirements, installation, a first entity and its use
- [Basics](docs/examples/basics.md): entities, reading by key, queries, scans, counts, filters, puts, inserts and deletes
- [Updates, conditions and transactions](docs/examples/writes.md): partial and nested updates, update expressions, upserts, conditional writes and transactions
- [Paginated listing](docs/examples/paginated-listing.md): previous and next links, page numbers, and pages
  merged from several queries
- [Custom types, normalizers, filters and update actions](docs/examples/extending.md)
- [Testing](docs/examples/testing.md): doubles of the client, and tests of filters, update actions and normalizers of your own
- [Exceptions](docs/examples/exceptions.md): which exceptions to catch, and what each one means
- [Limitations](docs/LIMITATIONS.md): what the entity model rules out, such as subclasses of an entity, and values
  that PHP and DynamoDB don't share, such as a float's precision and range
- [Architecture decisions](docs/adr/)

## Development tooling

In the `dev` environment, the bundle adds three console commands:

| Command | Output |
|---|---|
| `dal:definition:inspect [entity]` | The compiled definition of an entity: its table, keys and indexes, and every field's type, nullability, default, serializer and attribute type |
| `dal:definition:validate` | Compares every definition with its live table: the table and index keys, their attribute types, and whether each declared index exists and projects every field. Fails on any difference that makes requests fail, and warns of an index the definition does not declare |
| `dal:baseline:dump` | JSON with each entity's keys and indexes, and every field's attribute type and whether it is required. Commit it and diff it in CI to catch a field that turns required, or one stored as another type, before stored rows fail on it |

With `symfony/web-profiler-bundle` installed, the profiler gets a DynamoDB panel. It lists each call a request made
into the DAL, with its caller, the time spent in it and the DynamoDB requests it sent. The time is the wall time
inside the DAL, AsyncAws and the network included. The panel reads the requests from Symfony's traced HTTP client,
so AsyncAws has to send them through a client named `aws.base-client` (default):

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
