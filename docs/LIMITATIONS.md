# Limitations

- [Limitations](#limitations)
  - [Entities](#entities)
    - [An entity is exactly its configured class](#an-entity-is-exactly-its-configured-class)
    - [A table holds one entity class](#a-table-holds-one-entity-class)
    - [Entities are mutable data objects](#entities-are-mutable-data-objects)
    - [Entities don't reference each other](#entities-dont-reference-each-other)
    - [The bundle tracks no changes](#the-bundle-tracks-no-changes)
    - [The bundle neither creates nor migrates tables](#the-bundle-neither-creates-nor-migrates-tables)
  - [Reads and writes](#reads-and-writes)
    - [An upsert takes two requests for a new row](#an-upsert-takes-two-requests-for-a-new-row)
    - [A page is reached by token, not by number](#a-page-is-reached-by-token-not-by-number)
  - [Types at a glance](#types-at-a-glance)
  - [Numbers](#numbers)
    - [Floats are binary, DynamoDB numbers are decimal](#floats-are-binary-dynamodb-numbers-are-decimal)
    - [The float format depends on `serialize_precision`](#the-float-format-depends-on-serialize_precision)
    - [DynamoDB's range is narrower than a float's](#dynamodbs-range-is-narrower-than-a-floats)
    - [Ints hold 19 digits, DynamoDB numbers 38](#ints-hold-19-digits-dynamodb-numbers-38)
    - [Numbers from other writers change on the next put](#numbers-from-other-writers-change-on-the-next-put)
  - [Dates and times](#dates-and-times)
  - [Strings](#strings)
  - [Arrays](#arrays)
    - [JSON fields turn whole floats into ints](#json-fields-turn-whole-floats-into-ints)
    - [Numeric string keys become int keys](#numeric-string-keys-become-int-keys)

The bundle maps one entity class onto one DynamoDB table. This page lists what that design rules out, and which values
don't survive the way between PHP and DynamoDB. For each, it says what the bundle does and how to work around it.
The rules an entity has to follow are in [Basics](examples/basics.md#the-entity).

## Entities

### An entity is exactly its configured class

Every input names an entity class, and the bundle looks up the definition by that class exactly. A subclass is
another class, even one that adds no field. A put, update or delete of a subclass instance throws
`UnknownEntityDefinitionException`, and so do a `Key` and a search that name the subclass. A read always creates the configured class. An entity therefore cannot be extended to add
behaviour, such as an application's subclass of a library's entity, and one table cannot hold several subclasses of
it.

`#[Table]` is not inherited, as no PHP attribute is. A subclass configured in place of its parent needs a `#[Table]`
of its own, or the container build fails. If the parent stays configured as well, the subclass also needs a `name`
and a table of its own (see [A table holds one entity class](#a-table-holds-one-entity-class)).

What does work is sharing fields. An abstract class or a trait can declare `#[Field]` properties, and each entity that
extends or uses it is configured on its own:

```php
use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Attribute\Field;
use Shopware\DynamodbDalBundle\Attribute\Table;

abstract class TimestampedEntity extends AbstractEntity
{
    #[Field]
    public \DateTimeImmutable $createdAt;
}

#[Table(name: 'order', hashKey: 'customerId', rangeKey: 'id')]
class OrderEntity extends TimestampedEntity
{
    // ...
}
```

An abstract class cannot be configured as an entity itself.

### A table holds one entity class

A row does not record its entity class, so only its table tells which class it is. Configuring a second entity on
the same table fails the container build. Single-table design, which stores several kinds of entity in one table and
tells them apart by key prefixes, is not supported, and neither is a table of rows of several subclasses. Each kind
of entity needs a table of its own.

### Entities are mutable data objects

Every entity extends `AbstractEntity`, whose constructor is final and takes no arguments. The bundle creates an entity
with `new` and assigns each field to its property, on every read and after every write. An entity therefore cannot
require values when it is created, or check invariants there. A static factory can do both for the application's
code, but the bundle never calls it. A field has to be `public` or `protected`. A `private`, `readonly` or
`private(set)` field fails the container build.

Property hooks run when the bundle assigns a field. A `set` hook runs on every read and write-back as well, so it
sees stored values, not only those the application sets. A property with only a `get` hook, a virtual property,
passes the container build, and a put writes the value that the hook returns. Every read of the entity then fails
with an `\Error`, because the property cannot be assigned. A `#[Field]` property therefore has to be backed by a
value.

### Entities don't reference each other

A field holds a value, never another entity. A property typed as an entity class has no serializer and fails the
container build. The bundle has no relations, lazy loading, cascades or joins. Store the other entity's key in a
field, and read the entity with a request of its own. `findMany()` reads the keys of several entity classes in one
call. A value object needs a [field type of your own](examples/extending.md#a-field-type-of-your-own). One that
implements `\JsonSerializable` can instead be stored as JSON and built back by a normalizer (see
[A `JsonSerializable` value object](examples/extending.md#a-jsonserializable-value-object)).

### The bundle tracks no changes

The bundle keeps no identity map and records no changes. Two reads of the same key return two separate objects, and
changing one changes neither the other nor the row. Saving an entity means one of two writes:

- A put writes the whole row from the entity. It overwrites what a concurrent writer changed after the entity was
  read. It also erases every attribute that the entity doesn't declare, such as a field that a newer version of the
  application added during a rolling deploy.
- An update writes only the fields it names, and brings the entity up to date afterwards.

The bundle adds no version attribute, so a guard against a lost update is a
[condition](examples/writes.md#conditional-writes) of the application's own.

### The bundle neither creates nor migrates tables

The bundle creates no tables or indexes. Create them with the keys and indexes the entity declares, using your
infrastructure tooling. Changing an entity doesn't change the rows stored before. A field added later is missing
from every existing row, and a field whose storage format changes no longer reads from them. The only hook is the
entity's [normalizer](examples/extending.md#a-normalizer), which can fill a field in for older rows as they are
read. What else to watch for is in [Basics](examples/basics.md#the-entity).

## Reads and writes

### An upsert takes two requests for a new row

Every update checks that its row exists, so an update of a missing key fails, unlike DynamoDB's `UpdateItem`. An
upsert sends the update, and puts the entity where DynamoDB refuses the update because no row is stored. A single
`UpdateItem` can't do both: it can't write a map entry into a map that a new row doesn't have yet, and it can't create
the map and write into it at once. A new row therefore costs two writes, the refused update included, and an upsert
can't be part of a transaction (see [Upserts](examples/writes.md#upserts)).

### A page is reached by token, not by number

A search pages with tokens, not with an offset. Each token holds the key of an entity at the edge of a page. A listing
therefore cannot open page 10 without reading pages 1 to 9 first. Page numbers need a `CursorHistory`, which holds
only the pages visited, and which grows in the URL with every page. A scan cannot be read backward, so going back
through one needs a history as well (see [Paginated listing](examples/paginated-listing.md#which-approach-to-use)).

## Types at a glance

PHP and DynamoDB don't share a type system. A PHP float is a binary double, and a DynamoDB number is a decimal of up
to 38 digits. A PHP string is a sequence of bytes, and a DynamoDB string is UTF-8 text. A value that one side can
hold and the other cannot is refused before the request, sent for DynamoDB to reject, or changed without an error.

| PHP type | Stored as | What changes on the way |
|---|---|---|
| `float` | number (`N`) | Nothing within [DynamoDB's range](#dynamodbs-range-is-narrower-than-a-floats) and at the [default `serialize_precision`](#the-float-format-depends-on-serialize_precision), apart from `-0.0`, which reads back as `0.0` |
| `int` | number (`N`) | Nothing the bundle writes. A stored number [beyond 64 bits or with a fraction](#ints-hold-19-digits-dynamodb-numbers-38) reads back changed |
| `string` | string (`S`) | Nothing, as long as it is [valid UTF-8](#strings) |
| `bool` | boolean (`BOOL`) | Nothing |
| `\DateTimeImmutable`, `\DateTime` | number (`N`), in epoch seconds | [Fractions of a second and the time zone](#dates-and-times) |
| Backed enum | string (`S`) | Nothing, but an int-backed enum is stored as a string, so a range filter compares text: `10` sorts before `9` |
| Symfony `Uid` | string (`S`) | Nothing |
| `array` with `@var list<T>` | list (`L`) | What its element type loses |
| `array` with `@var array<K, V>` | map (`M`) | What its value type loses, and [numeric string keys become ints](#numeric-string-keys-become-int-keys) |
| `array` without a docblock, `\JsonSerializable` | JSON string (`S`) | [Whole floats become ints](#json-fields-turn-whole-floats-into-ints), and a `\JsonSerializable` object [reads back as an array](examples/extending.md#a-jsonserializable-value-object) |

A `null` field is never stored as DynamoDB's `NULL`: a put leaves it out, and an update removes it. The other way
round, a `NULL` that another writer stored in a field fails the read with a `MissingAttributeValueException`, even
for a nullable field. A union of types, such as `string|float`, or a class that no serializer claims fails the
container build.

## Numbers

### Floats are binary, DynamoDB numbers are decimal

A PHP float is an IEEE 754 double, a binary fraction with about 17 significant decimal digits. A DynamoDB number is a
decimal with up to 38 significant digits. Most decimals, such as `0.1`, have no exact binary form, so the float `0.1`
is only the double closest to it.

[`FloatFieldSerializer`](../src/Serializer/Field/FloatFieldSerializer.php) writes a float through `json_encode()`,
which writes the shortest decimal that reads back as the same double: `0.1` for `0.1`, and `0.30000000000000004` for
`0.1 + 0.2`. DynamoDB stores that decimal exactly, and the read casts it back with `(float)`. Every float the bundle
writes therefore reads back as the same float.

The two sides still disagree where a value is compared or computed:

- DynamoDB compares numbers as decimals, and exactly. `Filter::equals('amount', 0.1 + 0.2)` sends
  `0.30000000000000004`, which does not match a row whose `amount` is `0.3`. `Filter::equals('amount', 0.3)` matches
  that row, but not one whose `amount` was put as `0.1 + 0.2`.
- DynamoDB computes in decimal. Ten `Update::increment('amount', 0.1)` bring a field from `0` to exactly `1`, and the
  entity reads back `1.0`. The same ten additions in PHP give `0.9999999999999999`. A field that is sometimes computed
  in PHP and put, and sometimes incremented by DynamoDB, ends up with values that neither side would compute alone.

For money and other amounts that have to add up, store minor units in an `int` field, such as `$amountCents`. For
more digits than an int holds, write a [field type of your own](examples/extending.md#a-field-type-of-your-own) that
stores a decimal value object as `N`, written from its string form.

### The float format depends on `serialize_precision`

`json_encode()` follows the `serialize_precision` ini setting. At PHP's default of `-1`, it writes the shortest decimal
that reads back as the same double, as described above. Any other value makes it write that many significant digits:

- At `14`, `0.1 + 0.2` is stored as `0.3`, so the float that reads back is not the one that was written.
- At `17`, `0.1` is stored and compared as `0.10000000000000001`, which does not equal a `0.1` stored at `-1` or by
  another writer.

The setting applies to the whole process. It changes what every float field stores, and every float in a filter
operand or a JSON field. Keep it at `-1`.

### DynamoDB's range is narrower than a float's

A DynamoDB number is zero, or its magnitude lies between `1E-130` and `9.9999999999999999999999999999999999999E+125`.
A float reaches up to about `1.8E+308` and down to about `4.9E-324`. The bundle doesn't check the range. A put, an
update or a filter with a float outside it is sent, and DynamoDB rejects it with a `ValidationException` ("Number
overflow" or "Number underflow"). AsyncAws throws it as an `AsyncAws\Core\Exception\Http\ClientException` (see
[Requests DynamoDB rejects](examples/exceptions.md#requests-dynamodb-rejects)).

DynamoDB has no `NAN`, `INF` or `-INF`. The bundle refuses them before the request with a `FieldSerializationException`,
whose `getPrevious()` is the `\JsonException` from `json_encode()`.

### Ints hold 19 digits, DynamoDB numbers 38

A PHP int ranges from `PHP_INT_MIN` to `PHP_INT_MAX`, 19 digits either way, so every int fits into a DynamoDB number.
[`IntFieldSerializer`](../src/Serializer/Field/IntFieldSerializer.php) writes it exactly. The read casts the stored
number with `(int)`, which changes a number that is not a PHP int without an error:

- A number beyond the range saturates: `99999999999999999999` reads as `PHP_INT_MAX`.
- A fraction is cut off towards zero: `12.7` reads as `12`, and `-12.7` as `-12`.

DynamoDB counts past `PHP_INT_MAX`. `Update::increment('counter')` on a counter at `PHP_INT_MAX` stores
`9223372036854775808`, and the entity reads back `PHP_INT_MAX`, without an error. The next put writes `PHP_INT_MAX`
back and lowers the counter.

An `int` field takes only ints. A float, such as the value of `['counter' => 1.5]` or the step of
`Update::increment('counter', 0.5)`, throws a `WrongTypeException` before the request. A `float` field takes ints
too, and stores them exactly, but reads them back as floats. `['amount' => 9007199254740993]` in an update stores
`9007199254740993`, and the entity reads back `9007199254740992.0`. Only an int given in an update does this, since
a float property already holds a float.

### Numbers from other writers change on the next put

A row that a backfill, a script or another service wrote can hold numbers the entity cannot. A `float` field rounds
such a number to about 17 significant digits: `0.12345678901234567890123456789012345678` reads as
`0.12345678901234568`. An `int` field saturates it or cuts off its fraction, as described above. Neither throws.

A put writes the whole row from the entity, so a row that is read and put back stores the changed number in place of
the original. An update writes only the fields it names, and leaves the others as they are stored.

The bundle tells number keys apart by their value as PHP reads it, an int where it fits and a float otherwise. Two
keys that differ only past a float's digits, such as `12345678901234567890123` and `12345678901234567890124`, are
therefore one key to the bundle, although DynamoDB stores them as two. A batch or transaction that names both is
refused with a `DuplicateKeyException`.

## Dates and times

[`DateTimeFieldSerializer`](../src/Serializer/Field/DateTimeFieldSerializer.php) stores `getTimestamp()`, the epoch
seconds, as a number. A date loses its fraction of a second and its time zone on the way:
`2026-09-28 12:34:56.789` in `Europe/Berlin` reads back as `2026-09-28 10:34:56.000000` in UTC. Compare dates as
instants, not by their `format()`. Where the time zone matters, store it in a field of its own.

A row that another writer stored with a fraction, such as `1700000000.75`, reads back with its microseconds, and the
next put stores whole seconds.

A date property has to be typed `\DateTimeImmutable` or `\DateTime`. `\DateTimeInterface` offers no way to construct
a value, and a subclass, such as `Carbon\CarbonImmutable`, has no serializer either, so both fail the container build
unless a [field type of your own](examples/extending.md#a-field-type-of-your-own) claims them.

## Strings

DynamoDB strings are Unicode text, stored as UTF-8. A PHP string is a sequence of bytes, and
[`StringFieldSerializer`](../src/Serializer/Field/StringFieldSerializer.php) passes it on unchanged. A string that is
not valid UTF-8, such as the output of `random_bytes()` or `hash('sha256', $data, true)`, or text in Latin-1, fails
when AsyncAws encodes the request as JSON. It throws a `\JsonException` ("Malformed UTF-8 characters"), for a put, an
update, a key or a filter alike. The bundle doesn't wrap it, so `catch (DALException)` doesn't catch it, and it names
no field.

The bundle has no built-in field type for DynamoDB's binary (`B`). Encode bytes as text first, such as with
`bin2hex()` or `base64_encode()`, or write a
[field type of your own](examples/extending.md#a-field-type-of-your-own) that stores them as `B`.

An empty string is stored and read back like any other, except as a key: DynamoDB rejects an empty string as the
hash key or range key of the table or of an index, with a `ValidationException`.

## Arrays

### JSON fields turn whole floats into ints

[`JsonFieldSerializer`](../src/Serializer/Field/JsonFieldSerializer.php) writes with `json_encode()` and reads with
`json_decode()`. JSON has a single number type, and `json_encode()` writes a whole float without a fraction. So
`['price' => 10.0]` reads back as `['price' => 10]`, with an int, while `['rate' => 0.5]` keeps its float. An integer
beyond `PHP_INT_MAX` that another writer stored reads back as a float. The floats inside follow
[`serialize_precision`](#the-float-format-depends-on-serialize_precision) as well.

Code that checks `is_float()` or compares with `===` sees the difference. A `list<float>` or `array<string, float>`
field stores each value as a number and keeps the type.

### Numeric string keys become int keys

PHP turns an array key that is a decimal integer, such as `'123'` or `'-1'`, into an int. A map field stores
`['123' => …]` under the key `"123"`, and reads it back as `[123 => …]`. A JSON field does the same. A key such as
`'0123'` or `'1.5'` stays a string. Code that passes such a key to a `string` parameter under `strict_types` gets a
`\TypeError`.
