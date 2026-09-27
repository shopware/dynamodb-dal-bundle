# Exceptions

- [Exceptions](#exceptions)
  - [Exceptions to catch](#exceptions-to-catch)
  - [Stored data that doesn't match the entity](#stored-data-that-doesnt-match-the-entity)
  - [Mistakes in the calling code](#mistakes-in-the-calling-code)
  - [Requests DynamoDB rejects](#requests-dynamodb-rejects)
  - [When a read throws](#when-a-read-throws)

Every exception the bundle throws implements `Shopware\DynamodbDalBundle\Exception\DALException`, so catching
`DALException` catches all of them. The class says what went wrong. Its public readonly properties say where, such
as the entity definition and the field.

Two programming errors throw an SPL exception instead, which `DALException` doesn't catch: a `\LogicException` for
reading an output twice, and an `\InvalidArgumentException` for a token of an entity that is not on the page.

Errors from DynamoDB are not wrapped. They reach the caller as AsyncAws throws them.

| Group | Thrown | What to do |
|---|---|---|
| [Exceptions to catch](#exceptions-to-catch) | At runtime, by DynamoDB or for a token from the request | Catch and handle them |
| [Stored data that doesn't match the entity](#stored-data-that-doesnt-match-the-entity) | When a row is read | Fix the entity or migrate the rows |
| [Mistakes in the calling code](#mistakes-in-the-calling-code) | By the bundle, before any request is sent | Fix the code |
| [Requests DynamoDB rejects](#requests-dynamodb-rejects) | By DynamoDB | Fix the code |

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
entity was deployed.

| Exception | Thrown when |
|---|---|
| `FieldMissingDeserializedValueException` | The row lacks a field that is neither nullable nor has a default, and the normalizer did not fill it in. Typically, the field was added to an entity whose table already has rows. See [The entity](basics.md#the-entity) |
| `MissingAttributeValueException` | The stored attribute is not of the type the field's serializer reads, such as a number stored as a string |
| `FieldDeserializationException` | The field's serializer failed in another way, such as for a stored value that is no longer a case of the field's enum. `getPrevious()` holds the original error |

`dal:baseline:required-fields` catches a field becoming required in CI, before it reaches stored rows.

An update keyed by an entity reads the stored row after DynamoDB has stored the write. When it throws one of these,
the update is stored nevertheless, so don't retry it. See
[Keeping the entity in sync](writes.md#keeping-the-entity-in-sync).

A `JsonSerializable` value object reads back as an array. Assigning that array to the property fails with a
`\TypeError`, which is not a `DALException`. See
[A `JsonSerializable` value object](extending.md#a-jsonserializable-value-object).

## Mistakes in the calling code

The bundle throws these before it sends a request.

| Exception | Thrown when |
|---|---|
| `UnknownEntityDefinitionException` | The entity class is not listed under `shopware_dynamodb_dal.entities` |
| `UnknownFieldException` | A filter or update names a field the entity doesn't have. Also a path that the field's type has no place for, such as an index into a map or a name inside a list |
| `WrongTypeException` | A value is not of the type the field's serializer takes, such as a string for an `int` field, or a step of `0.5` for `increment()` on one |
| `NullOperandException` | A filter or condition compares with `null`. To match a missing attribute, use `Filter::notExists()` |
| `AttributeTypeMismatchException` | A filter or update does something the field's stored type doesn't allow, such as `beginsWith()` on a list, `append()` to a map, or a comparison of two operands of different types. A field whose serializer declares no type is left to DynamoDB |
| `FieldMissingSerializedValueException` | A put leaves a required field uninitialized or `null`, an update removes a field that is not nullable, or a key lacks a value |
| `FieldSerializationException` | The field's serializer failed with an error that is not a `DALException`. `getPrevious()` holds the original error |
| `ConditionEmptyException` | A key condition or a write condition checks nothing, such as an empty `Filter::and()` or `Filter::equalsAny([])` |
| `UpdateEmptyException` | An update has nothing to write |
| `UpdateDuplicatePathException` | An update gives one path two values, such as a field and a `setIfNotExists()` for the same path |
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
| A query of an index the table doesn't have, such as a typo in `index:` | [Querying](basics.md#querying) |
| A key condition that names a field outside the key, leaves out the partition key, or uses `or()`, `not()`, `equalsAny()` or `contains()` | [Querying](basics.md#querying) |
| An `equalsAny()` with more than 100 values, or an expression over 4 KB or 300 operators | [Filters](basics.md#filters) |
| A pagination token of an index that `#[Table]` doesn't declare | [Querying](basics.md#querying) |
| A pagination token that was edited, but still passes the bundle's checks | [Listing a query](paginated-listing.md#listing-a-query) |
| A pagination token from another partition of the same index | [Keeping filters in the links](paginated-listing.md#keeping-filters-in-the-links) |

## When a read throws

`search()`, `get()` and `findMany()` return an output without reading anything. Every exception of the read, from
the bundle or from DynamoDB, is thrown when the output is read. Put the `try` around `foreach`, `toArray()`,
`first()`, `page()`, `forEntity()` or `grouped()`, not around the call that returns the output.

`find()`, `count()` and `refresh()` read right away, so they throw during the call. So do all writes.
