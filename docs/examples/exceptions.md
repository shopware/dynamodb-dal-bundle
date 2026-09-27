# Exceptions

- [Exceptions](#exceptions)
  - [Exceptions to catch](#exceptions-to-catch)
  - [Stored data that doesn't match the entity](#stored-data-that-doesnt-match-the-entity)
  - [Values that don't fit their field](#values-that-dont-fit-their-field)
  - [Invalid filters, conditions and updates](#invalid-filters-conditions-and-updates)
  - [Other mistakes in the calling code](#other-mistakes-in-the-calling-code)
  - [Requests DynamoDB rejects](#requests-dynamodb-rejects)
  - [When a read throws](#when-a-read-throws)

Every exception the bundle throws implements `Shopware\DynamodbDalBundle\Exception\DALException`, so catching
`DALException` catches all of them. The class says what went wrong. Its public readonly properties say where, such
as the entity definition and the field.

Most classes also implement one of three interfaces in the same namespace. Each interface groups one kind of
failure, so catching it catches every class of the group, wherever the bundle throws it.

Two programming errors throw an SPL exception instead, which `DALException` doesn't catch: a `\LogicException` for
reading an output twice, and an `\InvalidArgumentException` for a token of an entity that is not on the page.

Errors from DynamoDB are not wrapped. They reach the caller as AsyncAws throws them.

| Section | Catch | Thrown | What to do |
|---|---|---|---|
| [Exceptions to catch](#exceptions-to-catch) | Each exception | At runtime, by DynamoDB or for a token from the request | Catch and handle them |
| [Stored data that doesn't match the entity](#stored-data-that-doesnt-match-the-entity) | `DeserializationException` | When a row is read | Fix the entity or migrate the rows |
| [Values that don't fit their field](#values-that-dont-fit-their-field) | `SerializationException` | By the bundle, before any request is sent | Fix the code |
| [Invalid filters, conditions and updates](#invalid-filters-conditions-and-updates) | `ExpressionException` | By the bundle, before any request is sent | Fix the code |
| [Other mistakes in the calling code](#other-mistakes-in-the-calling-code) | Each exception | By the bundle | Fix the code |
| [Requests DynamoDB rejects](#requests-dynamodb-rejects) | `AsyncAws\Core\Exception\Http\ClientException` | By DynamoDB | Fix the code |

A wrongly declared entity never gets this far. It fails the container build with a `\LogicException`.

## Exceptions to catch

These are the only exceptions a correct application should expect at runtime.

| Exception | Thrown when | Handling |
|---|---|---|
| `AsyncAws\DynamoDb\Exception\ConditionalCheckFailedException` | The condition of a put, update or delete does not hold, or the row an update addresses does not exist | Reload the entity and try again, or report a conflict. See [Conditional writes](writes.md#conditional-writes) |
| `AsyncAws\DynamoDb\Exception\TransactionCanceledException` | DynamoDB cancels a transaction, for example because a condition failed. The bundle retries a cancellation caused only by a conflict with another transaction first | `getCancellationReasons()` holds one reason per operation, in the order the operations were given. See [Transactions](writes.md#transactions) |
| `InvalidCursorException` | A pagination token or `CursorHistory` was edited, or belongs to another table or index | Start over at the first page. See [Paginated listing](paginated-listing.md#listing-a-query) |

Other AsyncAws exceptions, such as `ProvisionedThroughputExceededException`, pass through as well.

## Stored data that doesn't match the entity

The bundle throws these when it turns a stored row into an entity. That happens on every read, and when an update
gets the stored row back. Every affected row fails, so these exceptions usually show up right after a change to an
entity was deployed. They implement `DeserializationException`.

| Exception | Thrown when |
|---|---|
| `FieldMissingDeserializedValueException` | The row lacks a field that is neither nullable nor has a default, and the normalizer did not fill it in. Typically, the field was added to an entity whose table already has rows. See [The entity](basics.md#the-entity) |
| `MissingAttributeValueException` | The stored attribute is not of the type the field's serializer reads, such as a number stored as a string |
| `FieldDeserializationException` | The field's serializer failed in another way, such as for a stored value that is no longer a case of the field's enum. `getPrevious()` holds the original error |

A JSON field whose stored JSON holds a scalar instead of an array or object throws a `WrongTypeException`, which is a
[`SerializationException`](#values-that-dont-fit-their-field), not a `DeserializationException`.

`dal:baseline:required-fields` catches a field becoming required in CI, before it reaches stored rows.

An update keyed by an entity reads the stored row after DynamoDB has stored the write. When it throws one of these,
the update is stored nevertheless, so don't retry it. See
[Keeping the entity in sync](writes.md#keeping-the-entity-in-sync).

```php
use Shopware\DynamodbDalBundle\Exception\DeserializationException;

try {
    $this->client->update(new UpdateInput($order, Update::increment('totalCents', 499)));
} catch (DeserializationException $e) {
    // The increment is stored, only $order could not take the stored row back. A retry would count it twice.
    $this->logger->error('A stored order does not match its entity', ['exception' => $e]);
}
```

A `JsonSerializable` value object reads back as an array. Assigning that array to the property fails with a
`\TypeError`, which is not a `DALException`. See
[A `JsonSerializable` value object](extending.md#a-jsonserializable-value-object).

## Values that don't fit their field

The bundle throws these before it sends a request. It throws them for the entity of a put, for a key, and for a value
in a filter, condition or update alike. They implement `SerializationException`, and their `$fieldDefinition` names
the field.

| Exception | Thrown when |
|---|---|
| `WrongTypeException` | A value is not of the type the field's serializer takes, such as a string for an `int` field, or a step of `0.5` for `increment()` on one. Also a size compared with anything but a number. On a read, also a JSON field whose stored JSON holds no array or object |
| `FieldMissingSerializedValueException` | A put leaves a required field uninitialized or `null`, an update removes a field that is not nullable, or a key lacks a value |
| `FieldSerializationException` | The field's serializer failed with an error that is not a `DALException`. `getPrevious()` holds the original error |

A `DALException` from a [field serializer of your own](extending.md#a-field-type-of-your-own) reaches the caller
as it is, in the group its class implements.

## Invalid filters, conditions and updates

The bundle throws these before it sends a request. They implement `ExpressionException`.

| Exception | Thrown when |
|---|---|
| `UnknownFieldException` | A filter or update names a field the entity doesn't have. Also a path that the field's type has no place for, such as an index into a map or a name inside a list |
| `NullOperandException` | A filter or condition compares with `null`. To match a missing attribute, use `Filter::notExists()` |
| `AttributeTypeMismatchException` | A filter or update does something the field's stored type doesn't allow, such as `beginsWith()` on a list, `append()` to a map, or a comparison of two operands of different types. A field whose serializer declares no type is left to DynamoDB |
| `InvalidKeyConditionException` | A key filter names a field that is not the hash or range key of the table or index queried, has a range key where the key has none, or compares a key with a `Filter::size()` or `Filter::field()`. See [Querying](basics.md#querying) |
| `ConditionEmptyException` | A write condition checks nothing, such as an empty `Filter::and()` or `Filter::equalsAny([])` |
| `UpdateEmptyException` | An update has nothing to write |
| `UpdateDuplicatePathException` | An update gives one path two values, such as a field and a `setIfNotExists()` for the same path |

A value in a filter, condition or update that doesn't fit its field throws a `SerializationException`, as it does in
a put. To catch everything a filter can do wrong, catch `ExpressionException|SerializationException`.

## Other mistakes in the calling code

These belong to no group.

| Exception | Thrown when |
|---|---|
| `UnknownEntityDefinitionException` | The entity class is not listed under `shopware_dynamodb_dal.entities` |
| `UnknownIndexException` | A query names an index that `#[Table]` doesn't declare |
| `\LogicException` | An output is read a second time. Run the search or key read again instead |
| `\InvalidArgumentException` | `Page::cursorAfter()` or `cursorBefore()` gets an entity that is not on that page |

## Requests DynamoDB rejects

The bundle doesn't check everything DynamoDB refuses. The following requests are sent and fail at DynamoDB. They throw
an `AsyncAws\Core\Exception\Http\ClientException` whose `getAwsCode()` is `ValidationException`.

| Request | See |
|---|---|
| An update of a key field of the table | [Partial updates](writes.md#partial-updates) |
| An update path into a map or list that the row doesn't have | [Nested updates](writes.md#nested-updates) |
| An update whose paths overlap, such as `set('totalCents', 0)` with `increment('totalCents')` | [Update expressions](writes.md#update-expressions) |
| `addToSet()` with an empty set | [Update expressions](writes.md#update-expressions) |
| A batch that writes the same entity twice | [Batches](writes.md#batches) |
| A transaction with more than one operation on the same entity | [Transactions](writes.md#transactions) |
| `consistentRead: true` on a query of a global secondary index | [Querying](basics.md#querying) |
| A query of an index that `#[Table]` declares, but the table doesn't have | [Querying](basics.md#querying) |
| An `equalsAny()` with more than 100 values, or an expression over 4 KB or 300 operators | [Filters](basics.md#filters) |
| A pagination token that was edited, but still passes the bundle's checks | [Listing a query](paginated-listing.md#listing-a-query) |
| A pagination token from another partition of the same index | [Keeping filters in the links](paginated-listing.md#keeping-filters-in-the-links) |

## When a read throws

`search()`, `get()` and `findMany()` return an output without reading anything. Every exception of the read, from
the bundle or from DynamoDB, is thrown when the output is read. Put the `try` around `foreach`, `toArray()`,
`first()`, `page()`, `forEntity()` or `grouped()`, not around the call that returns the output.

`find()`, `count()` and `refresh()` read right away, so they throw during the call. So do all writes.
