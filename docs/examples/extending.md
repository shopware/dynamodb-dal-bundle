# Custom types, normalizers, filters and update actions

- [Custom types, normalizers, filters and update actions](#custom-types-normalizers-filters-and-update-actions)
  - [A field type of your own](#a-field-type-of-your-own)
    - [How it works](#how-it-works)
    - [Pitfalls](#pitfalls)
  - [A `JsonSerializable` value object](#a-jsonserializable-value-object)
    - [Pitfalls](#pitfalls-1)
  - [A normalizer](#a-normalizer)
    - [How it works](#how-it-works-1)
    - [Pitfalls](#pitfalls-2)
  - [A filter of your own](#a-filter-of-your-own)
    - [How it works](#how-it-works-2)
    - [Pitfalls](#pitfalls-3)
  - [An update action of your own](#an-update-action-of-your-own)
    - [How it works](#how-it-works-3)
    - [Pitfalls](#pitfalls-4)
  - [An update action the normalizer sees](#an-update-action-the-normalizer-sees)
    - [How it works](#how-it-works-4)
    - [Pitfalls](#pitfalls-5)

The bundle has four extension points:

- A field serializer stores a type the bundle doesn't know.
- A normalizer holds rules that span several fields of an entity.
- A filter or an update action adds DynamoDB syntax that `Filter` and `Update` don't cover.

The snippets build on the `OrderEntity` from [Basics](basics.md). [Testing](testing.md) shows how to test each
extension on its own.

## A field type of your own

Each `#[Field]` property is handled by a field serializer that supports its type. For a type the bundle doesn't know,
such as a value object, write one:

```php
namespace App\Money;

final readonly class Money
{
    public function __construct(
        public int $cents,
        public string $currency,
    ) {
    }
}
```

```php
namespace App\Money;

use AsyncAws\DynamoDb\ValueObject\AttributeValue;
use Shopware\DynamodbDalBundle\Definition\AttributeType;
use Shopware\DynamodbDalBundle\Definition\FieldDefinition;
use Shopware\DynamodbDalBundle\Exception\MissingAttributeValueException;
use Shopware\DynamodbDalBundle\Exception\WrongTypeException;
use Shopware\DynamodbDalBundle\Serializer\Field\AbstractFieldSerializer;

/**
 * Stores a Money as a string like "1999 EUR".
 *
 * @extends AbstractFieldSerializer<Money, class-string<Money>>
 */
final class MoneyFieldSerializer extends AbstractFieldSerializer
{
    public static function supports(string $type, ?string $docblockType = null): bool
    {
        return $type === Money::class;
    }

    public function getAttributeType(FieldDefinition $definition): AttributeType
    {
        return AttributeType::String;
    }

    public function serialize(FieldDefinition $definition, mixed $value): AttributeValue
    {
        if (!$value instanceof Money) {
            throw new WrongTypeException($definition, Money::class, $value);
        }

        return AttributeValue::create(['S' => \sprintf('%d %s', $value->cents, $value->currency)]);
    }

    public function deserialize(FieldDefinition $definition, AttributeValue $attributeValue): Money
    {
        $value = $attributeValue->getS() ?? throw new MissingAttributeValueException($definition, 'S');

        [$cents, $currency] = explode(' ', $value, 2);

        return new Money((int) $cents, $currency);
    }
}
```

```php
#[Field]
public Money $total;
```

| Method | Runs | Does |
|---|---|---|
| `supports($type, $docblockType)` | For every field, while the container is built | Claims the property types the serializer handles |
| `getAttributeType($definition)` | When a filter or update on the field is compiled | Declares the DynamoDB type that `serialize()` writes |
| `serialize($definition, $value)` | For every value written, and every value of a key or filter | Turns the PHP value into an `AttributeValue` |
| `deserialize($definition, $attributeValue)` | For every value read | Turns the stored `AttributeValue` back into the PHP value |

The serializer has to be a service. The bundle finds every service that extends `AbstractFieldSerializer`, so it
needs no autoconfiguration.

Filters and conditions on the field serialize their values with this serializer too, for example
`Filter::equals('total', new Money(1999, 'EUR'))`.

Throw `WrongTypeException` or `MissingAttributeValueException`, so that the error names the field.

### How it works

- Your serializer takes precedence over the bundle's own, wherever it is registered. If several of yours claim the
  same type, the first one registered wins. To order them otherwise, tag them as `AbstractFieldSerializer` with a
  `priority`, higher first.
- Filters and update actions check what they do with the field against `getAttributeType()`, such as
  `Filter::beginsWith()` against a string or `Update::append()` against a list. Where DynamoDB would reject the
  expression or never match it, they throw `AttributeTypeMismatchException` before the request.
- The bundle wraps any other exception in a `FieldSerializationException` or `FieldDeserializationException` that
  names the field.
- A prefix or substring is a part of the stored string, not a value of the field. `Filter::beginsWith('total', '1999 ')`
  therefore matches 1999 of any currency.

### Pitfalls

- `supports()` runs for every field while the container is built. Claim only your own type. A serializer that claims
  more takes over fields of other types.
- Without `getAttributeType()`, the type is `null`, and every check passes. A filter that DynamoDB can never match
  then matches nothing, a write's condition fails as if the entity didn't match it, and a wrong update fails at
  DynamoDB, far from the code that wrote it.
- A value your serializer stores as a map has no paths. A filter or update of `total.currency` throws
  `UnknownFieldException`.
- A serializer applies to a PHP type, not to a property. Two properties of the same type are stored the same way,
  unless one of them gets a type of its own.

## A `JsonSerializable` value object

A property whose class implements `JsonSerializable` is written without a serializer of its own. The bundle stores
whatever `jsonSerialize()` returns as a JSON string. The property reads back as the decoded array, not as the object,
because the bundle has no way to build the object. Assigning that array to the property fails, so do one of these:

- give the type a [field serializer of its own](#a-field-type-of-your-own), which takes precedence over the JSON one,
  or
- turn the array back into the object in the entity's [normalizer](#a-normalizer):

```php
public function denormalize(NormalizerContext $context): void
{
    $address = $context->get('address');
    if (\is_array($address)) {
        $context->set('address', Address::fromArray($address));
    }
}
```

`jsonSerialize()` has to return an array. Anything else fails to serialize.

### Pitfalls

- Without either, every write succeeds, but every read fails with a `\TypeError`, which is not a `DALException`.
- Elements of a list or map of such a type read back as arrays too. The property is an `array`, so nothing fails, but
  the normalizer still has to convert the elements.

## A normalizer

A normalizer sees all fields of an entity together: before they are serialized, and after they are deserialized. It
fills in what no single field can: a key composed of other fields, generated values, a timestamp every update moves,
and fields that older rows lack. Its context says what it runs for, so a rule such as "stamp every update" lives in
the normalizer, instead of in every place that updates the entity.

```php
namespace App\Entity;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Attribute\Field;
use Shopware\DynamodbDalBundle\Attribute\Table;
use Symfony\Component\Uid\Uuid;

#[Table(name: 'ledger_entry', hashKey: 'pk', rangeKey: 'id', normalizer: LedgerEntryNormalizer::class)]
class LedgerEntryEntity extends AbstractEntity
{
    /**
     * "{accountId}#{currency}", set by the normalizer
     */
    #[Field]
    public string $pk;

    #[Field]
    public Uuid $id;

    #[Field]
    public string $accountId;

    #[Field]
    public string $currency;

    #[Field]
    public int $amountCents;

    #[Field]
    public \DateTimeImmutable $createdAt;

    /**
     * Set by the normalizer on every update
     */
    #[Field]
    public ?\DateTimeImmutable $updatedAt = null;

    /**
     * Added later; rows written before have none
     */
    #[Field]
    public string $source;
}
```

```php
namespace App\Entity;

use Shopware\DynamodbDalBundle\Serializer\AbstractNormalizer;
use Shopware\DynamodbDalBundle\Serializer\NormalizerContext;
use Shopware\DynamodbDalBundle\Serializer\NormalizerOperation;
use Symfony\Component\Uid\Uuid;

final class LedgerEntryNormalizer extends AbstractNormalizer
{
    public function normalize(NormalizerContext $context): void
    {
        // An update always hits a stored row, which keeps the creation time the put gave it
        if ($context->operation === NormalizerOperation::Update) {
            $context->set('updatedAt', new \DateTimeImmutable());
            $context->omit('createdAt');

            return;
        }

        if ($context->operation !== NormalizerOperation::Put) {
            return;
        }

        $context->setIfUnset('id', Uuid::v7());
        $context->setIfUnset('createdAt', new \DateTimeImmutable());

        $accountId = $context->get('accountId');
        $currency = $context->get('currency');
        if ($context->get('pk') === null && $accountId !== null && $currency !== null) {
            $context->set('pk', \sprintf('%s#%s', $accountId, $currency));
        }
    }

    public function denormalize(NormalizerContext $context): void
    {
        $context->setIfUnset('source', 'legacy');
    }
}
```

```php
$entry = new LedgerEntryEntity();
$entry->accountId = 'acc-7';
$entry->currency = 'EUR';
$entry->amountCents = -1_250;
$entry->source = 'checkout';

$this->client->put(new PutInput($entry));

$entry->pk; // "acc-7#EUR"
$entry->id; // the generated Uuid

$this->client->update(new UpdateInput($entry, ['amountCents' => -1_300]));

$entry->updatedAt; // stamped without the caller naming it
```

`#[Table(normalizer: ...)]` takes the normalizer's class name, which also has to be its service ID, as it is in a
standard Symfony application. Override only the side you need. The other side leaves the fields as they are.

`$context->operation` says what the normalizer runs for, and which fields it gets:

| Operation | Runs for | Fields present |
|---|---|---|
| `Put` | A put | Every field, `null` where the property is not initialized |
| `Update` | An update, whose row is known to exist | Only the paths the update writes, `null` for one it removes |
| `Key` | A lookup, delete or update by key | Only the key fields |
| `Read` | A row DynamoDB returns | Every field. A field the row lacks is `null`, or its default where the field is not nullable |

The context reads and changes the fields:

| Method | Does |
|---|---|
| `has($path)` | Tells whether the path is present, even as `null` |
| `get($path)` | Returns the path's value, or `null` where the path is `null` or not present |
| `hasWithin($path)`, `getWithin($path)` | Like `has()` and `get()`, for the path and every path nested under it, keyed by path. `meta` finds `meta.kind`, but not `metadata` |
| `set($path, $value)` | Adds the path, or replaces its value |
| `setIfUnset($path, $value)` | Sets the path only where it is present and `null`, such as a generated ID on a put. A path that is not present stays out |
| `remove($path)` | Makes the path absent: a put leaves the attribute out, and an update removes it |
| `omit($path)` | Leaves the path out entirely: an update doesn't touch the attribute, and a read doesn't set the property |

`setIfUnset()` never adds a path. An update that doesn't write `createdAt` therefore never overwrites the stored one,
and a key never gains an attribute. Use `set()` to add a path, such as `updatedAt` on an update.

### How it works

- A key runs through the normalizer on its own, as `Key`. That includes the key of an update, whose fields run
  separately, as `Update`.
- An update also passes the value an [action](writes.md#update-expressions) stores as given, under the action's
  path: the value of a `setIfNotExists()`, or the elements of an `append()`. The normalizer handles the value like
  any field, and never sees the action.
- After a put, the bundle writes the normalized fields back to the entity, generated values included. After an update
  in a transaction, which returns no row, it writes back the fields the update wrote, including those the normalizer
  added. `denormalize()` then runs as `Put` or `Update`, on the fields the write sent.
- A single update keyed by an entity gets the updated row back from DynamoDB instead. So does a transaction that
  reads an entity back after an update of a nested path or with an action. `denormalize()` then runs as `Read`, with
  every field. A rule that turns stored values into entity
  values, such as the `Address` above, therefore runs whatever the operation.
- A table key field may be nullable only on an entity with a normalizer, which then has to fill it in.

### Pitfalls

- Never assume a field is present. An update carries only the paths it writes, and a key only the key fields.
- An update may write into an attribute without naming it, such as `meta.kind` instead of `meta`. `has('meta')` is
  then `false`. Use `hasWithin('meta')` and `getWithin('meta')`.
- Generate nothing for a `Key`. A generated value there addresses a row that doesn't exist.
- A rule that has to hold for every operation, such as trimming or lowercasing an ID, goes before any check of the
  operation. A put then stores the form that a lookup by key asks for.
- Filters, a query's key condition included, don't pass through the normalizer. Pass them the canonical value.
- A value the normalizer leaves `null` on a required field fails as usual. On a read, `omit()` of a required field
  fails the same way.
- The normalizer can't map a stored enum value that is no longer a case onto a new case. It only sees a field after
  the field's serializer has read it, and the serializer already fails.
- `NormalizerOperation` may gain cases, so don't handle it exhaustively with a `match`.

## A filter of your own

`Filter` covers DynamoDB's comparisons and functions. Anything else can implement `FilterInterface`. Its `compile()`
returns an expression fragment, and registers the attribute names and values it uses on the `FilterCompileContext`.

```php
namespace App\Dal;

use Shopware\DynamodbDalBundle\Definition\AttributeType;
use Shopware\DynamodbDalBundle\Expression\Contract\FilterInterface;
use Shopware\DynamodbDalBundle\Expression\FilterCompileContext;

/**
 * Matches an entity whose field is stored as another type than its serializer writes today, such as a number an
 * older writer stored as a string: DynamoDB's `attribute_type()`.
 */
final readonly class StoredAsFilter implements FilterInterface
{
    public function __construct(
        private string $fieldName,
        private AttributeType $type,
    ) {
    }

    public function compile(FilterCompileContext $context): ?string
    {
        return \sprintf(
            'attribute_type(%s, %s)',
            $context->path($this->fieldName),
            $context->literal($this->type->value),
        );
    }
}
```

It works in a search's `filter:` and in write conditions, including inside `Filter::and()`, `or()` and `not()`:

```php
new QueryInput(
    OrderEntity::class,
    Filter::keyFilter(Filter::equals('customerId', 'c-42')),
    filter: Filter::and(Filter::equals('status', OrderStatus::Open), new StoredAsFilter('totalCents', AttributeType::String)),
);
```

The context builds every placeholder:

| Method | Returns |
|---|---|
| `path($fieldName, ...$types)` | The placeholder of a field, or of a path such as `meta.carrier`. `$types` names the types the stored field may have, as in `path('tags', AttributeType::List)` |
| `fieldDefinition($fieldName)` | The definition of what the path addresses. Its `getAttributeType()` is the type it is stored as, or `null` where its serializer declares none |
| `fieldValue($fieldName, $value)` | The placeholder of a value, serialized with the field's serializer |
| `elementValue($fieldName, $value)` | The placeholder of one element of a list or map field, such as the element `contains()` looks for |
| `literal($value)` | The placeholder of a string or number as it is, for an operand that is not a value of the field, such as the number a size is compared with, or the type name `attribute_type()` takes |
| `operand($operand, ...$types)` | One side of a comparison: the field, or its `size()` for a `Filter::size()` |
| `comparand($operand, $value)` | The other side: a value, a `Filter::field()` or a `Filter::size()`, of the same type as `$operand` |

A filter can also compile one of the bundle's filters, which does the same:

```php
return Filter::lessThanOrEquals(Filter::size($this->fieldName), $this->max)->compile($context);
```

`FilterCompileContext` extends `ExpressionCompileContext`, the context an update action gets, with `operand()` and
`comparand()`.

Return `null` to add nothing, for example for an optional criterion. Register no names or values in that case. If the
fragment joins several clauses with `AND` or `OR`, wrap it in parentheses, as `Filter::and()` and `Filter::or()` do:

```php
$status = $context->path('status');

return "({$status} = {$context->fieldValue('status', $this->status)} OR attribute_not_exists({$status}))";
```

### How it works

- `path()` throws `UnknownFieldException` for a field the entity doesn't have, or for a path the field has no place
  for, such as an index into a map. It throws `AttributeTypeMismatchException` for a field stored as a type other than
  the ones named.
- The bundle drops the parentheses that enclose a whole filter, condition or key condition. A filter that stands on
  its own goes out without them.

### Pitfalls

- Build every placeholder through the context. `FieldDefinition::getExpressionAttributeName()` and
  `getExpressionValueName()` return placeholders that the request never defines, and DynamoDB rejects them.
- A fragment that joins clauses without parentheses binds to what surrounds it. Next to an update's check that the row
  exists, `a OR b` goes out as `attribute_exists(#id) AND a OR b`. DynamoDB reads that as
  `(attribute_exists(#id) AND a) OR b`, so the update can create a row that did not exist.

## An update action of your own

[`Update`](writes.md#update-expressions) covers DynamoDB's update actions and functions. Anything else DynamoDB's
update syntax allows can implement `UpdateActionInterface`. Its `getClause()` names the clause the action belongs to,
and its `compile()` returns the fragment without the clause keyword. Its context is the `ExpressionCompileContext`,
which has no `operand()` or `comparand()`, since DynamoDB has no `size()` in an update.

```php
namespace App\Dal;

use Shopware\DynamodbDalBundle\Expression\Contract\UpdateActionInterface;
use Shopware\DynamodbDalBundle\Expression\ExpressionCompileContext;
use Shopware\DynamodbDalBundle\Expression\Update\UpdateClause;

/**
 * Copies one attribute's stored value to another path.
 */
final readonly class CopyAction implements UpdateActionInterface
{
    public function __construct(
        private string $from,
        private string $to,
    ) {
    }

    public function getClause(): UpdateClause
    {
        return UpdateClause::Set;
    }

    public function compile(ExpressionCompileContext $context): ?string
    {
        return "{$context->path($this->to)} = {$context->path($this->from)}";
    }
}
```

`Update::with()` takes it alongside the built-in actions:

```php
// DynamoDB evaluates every operand against the stored row, so the copy keeps the status before the change
$this->client->update(new UpdateInput(
    $order,
    Update::with(
        Update::set('status', OrderStatus::Cancelled),
        new CopyAction('status', 'meta.previousStatus'),
    ),
));
```

Return `null` to add nothing.

### How it works

- The bundle writes each clause once, in the order `SET`, `REMOVE`, `ADD`, `DELETE`. It joins the fragments of one
  clause with commas.
- An operand that is not a value of the field, such as a step, elements added to a set, or another path as
  `CopyAction` copies, stays as given. The normalizer never sees it.
- DynamoDB computes an action's result, so an entity updated in a transaction is read back afterwards. See
  [Keeping the entity in sync](writes.md#keeping-the-entity-in-sync).

### Pitfalls

- Any action makes a transaction read its entity back under `Refresh::Full`, even an action that returns `null` and
  writes nothing.
- DynamoDB rejects an update in which the action's path overlaps another path of the same update. See
  [Update expressions](writes.md#update-expressions).

## An update action the normalizer sees

An action whose input is a value of its field, as `setIfNotExists()` stores one, implements
`NormalizableUpdateActionInterface` instead. The entity's normalizer then sees that input under `getPath()`, like a
field the update sets. The action takes back what the normalizer leaves there through `withValue()`:

```php
/**
 * Copies another path's stored value, or writes the fallback where that path holds none.
 */
final readonly class CopyOrFallbackAction implements NormalizableUpdateActionInterface
{
    public function __construct(
        private string $from,
        private string $to,
        private mixed $fallback,
    ) {
    }

    public function getClause(): UpdateClause
    {
        return UpdateClause::Set;
    }

    // The fallback is stored as given at `to`, so it is what the normalizer sees there
    public function getPath(): string
    {
        return $this->to;
    }

    public function getValue(): mixed
    {
        return $this->fallback;
    }

    public function withValue(mixed $value): self
    {
        return new self($this->from, $this->to, $value);
    }

    public function compile(ExpressionCompileContext $context): ?string
    {
        // Where the normalizer removed the fallback, write nothing, as `setIfNotExists()` does.
        // A copy without a fallback would fail for a row that has no `from`.
        if ($this->fallback === null) {
            return null;
        }

        return \sprintf(
            '%s = if_not_exists(%s, %s)',
            $context->path($this->to),
            $context->path($this->from),
            $context->fieldValue($this->to, $this->fallback),
        );
    }
}
```

`withValue()` only rebuilds the action. It gets `null` where the normalizer removed the value, and `compile()`
decides what that writes, returning `null` for nothing.

An operand that is not a value of the field, as with `CopyAction` above, stays as given, and such an action implements
`UpdateActionInterface` alone.

### How it works

- Where the normalizer leaves the path out, the bundle drops the action from the update.
- A value of the wrong type fails in `fieldValue()`, whose field serializer refuses it with a `WrongTypeException`.

### Pitfalls

- A path can take only one value per update. Another action or a field that gives the same path a value throws
  `UpdateDuplicatePathException`.
