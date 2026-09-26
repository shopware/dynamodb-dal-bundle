# Custom types, normalizers, filters and update actions

- [Custom types, normalizers, filters and update actions](#custom-types-normalizers-filters-and-update-actions)
  - [A field type of your own](#a-field-type-of-your-own)
  - [A `JsonSerializable` value object](#a-jsonserializable-value-object)
  - [A normalizer](#a-normalizer)
  - [A filter of your own](#a-filter-of-your-own)
  - [An update action of your own](#an-update-action-of-your-own)

## A field type of your own

Each `#[Field]` property is handled by a field serializer that supports its type. For a type the bundle doesn't
know, such as a value object, write one:

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

- The serializer has to be a service. The bundle finds every service that extends `AbstractFieldSerializer`, so autoconfiguration is not needed.
- Your serializer takes precedence over the bundle's own, wherever it is registered. If several of yours claim the
  same type, the first registered wins. To order them otherwise, tag them as `AbstractFieldSerializer` with a
  `priority`, higher first.
- `supports()` runs for every field while the container is built. Claim only your own type.
- Filters and conditions on the field serialize their values with this serializer too, for example
  `Filter::equals('total', new Money(1999, 'EUR'))`.
- Throw `WrongTypeException` or `MissingAttributeValueException` so the error names the field. The bundle wraps
  any other exception in a `FieldSerializationException` or `FieldDeserializationException` that names the
  field.

## A `JsonSerializable` value object

A property whose class implements `JsonSerializable` is written without a serializer of its own: the bundle stores
whatever `jsonSerialize()` returns as a JSON string. It reads back as the decoded array, not as the object, because
the bundle has no way to build one. Assigning that array to the property fails with a `TypeError`, so either:

- give the type a [field serializer of its own](#a-field-type-of-your-own), which takes precedence over the JSON
  one, or
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

- `jsonSerialize()` has to return an array. Anything else fails to serialize.
- Elements of a list or map of such a type read back as arrays too. The property is an `array`, so nothing fails,
  but the normalizer still has to convert them.

## A normalizer

A normalizer sees all of an item's fields together: before they are serialized, and after they are
deserialized. It fills in what no single field can: a key composed from other fields, generated values, a
timestamp every update moves, and fields that older rows lack. Its context says what it runs for, so a rule
such as "stamp every update" lives here instead of in each place that updates the item.

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

`$context->operation` says what the normalizer runs for, and which fields it gets:

| Operation | Runs for | Fields present |
|---|---|---|
| `Put` | A put | Every field, `null` where the property is not initialized |
| `Update` | An update, whose row is known to exist | Only the paths the update writes, `null` for one it removes |
| `Key` | A lookup, delete or update by key | Only the key fields |
| `Read` | An item DynamoDB returns | Every field, `null` where the row has none, or the default of a field that is not nullable |

- `#[Table(normalizer: ...)]` takes the normalizer's class name, which also has to be its service ID, as it is in a
  standard Symfony application.
- Override only the side you need. The other one leaves the fields as they are.
- Never assume a field is present. `has()` tells whether it is, even as `null`, and `get()` returns `null` for
  one that is absent or not present.
- An update passes the fields it sets or removes, and under its path the value an
  [action](writes.md#update-expressions) stores as given, such as the one of `setIfNotExists()` or the elements of
  `append()`. The normalizer handles it like any field and never sees the action.
- An update may write into an attribute without naming it, such as `meta.kind` instead of `meta`.
  `hasWithin('meta')` and `getWithin('meta')` find the attribute and every path nested under it,
  but not `metadata`.
- `set()` adds or replaces a path. `remove()` makes it absent: a put leaves the attribute out, an update removes
  it. `omit()` leaves it out entirely: an update does not touch the attribute, and a read does not set the
  property. A read still needs every field that is neither nullable nor has a default, so omitting one fails like
  leaving it `null`.
- `setIfUnset()` fills a path only where it is present and `null`, such as a generated ID on a put. A path the
  fields do not name stays out, so an update that does not write `createdAt` never overwrites the stored one, and a
  key never gains an attribute. Use `set()` to add a path, such as `updatedAt` on an update.
- A key runs through the normalizer on its own, as `Key`. That includes an update's key, whose fields run as
  `Update` separately. Generate nothing for a key.
- A rule that has to hold for every operation, such as trimming or lowercasing an ID, goes before any check of
  the operation. A put then stores the form that a lookup by key asks for. Filters, a query's key condition
  included, do not pass through the normalizer, so pass them the canonical value.
- A value the normalizer leaves `null` on a required field fails as usual.
- After a put, generated values are written back to the entity. After an update in a transaction, which returns
  no item, the fields it wrote are, including those the normalizer added. `denormalize()` then runs as `Put` or
  `Update`, on the fields that write sent.
- A lone update keyed by an entity gets the updated item back from DynamoDB instead, as does the read-back after
  an update of a nested path or with an action. `denormalize()` then runs as `Read`, with every field. A rule that turns stored
  values into entity values, such as the `Address` above, therefore runs whatever the operation.
- A table key field may be nullable only on an entity with a normalizer, which then has to fill it in.
- A test builds the context with `NormalizerContext::fromFields()`, runs the normalizer and reads the fields back:

  ```php
  $context = NormalizerContext::fromFields(NormalizerOperation::Update, [
      'amountCents' => -1_300,
      'createdAt' => new \DateTimeImmutable('2026-01-01'),
  ]);

  new LedgerEntryNormalizer()->normalize($context);

  static::assertInstanceOf(\DateTimeImmutable::class, $context->get('updatedAt'));
  static::assertFalse($context->has('createdAt'));
  ```

## A filter of your own

`Filter` covers DynamoDB's comparisons and functions. Anything else can implement `FilterInterface`: its
`compile()` returns an expression fragment and registers the attribute names and values it uses on the context.

```php
namespace App\Dal;

use Shopware\DynamodbDalBundle\Expression\Contract\FilterInterface;
use Shopware\DynamodbDalBundle\Expression\ExpressionCompileContext;

/**
 * Matches a list or map with more than `$count` elements, or a string longer than `$count` characters.
 */
final readonly class SizeGreaterThanFilter implements FilterInterface
{
    public function __construct(
        private string $fieldName,
        private int $count,
    ) {
    }

    public function compile(ExpressionCompileContext $context): ?string
    {
        return \sprintf(
            'size(%s) > %s',
            $context->path($this->fieldName),
            $context->number($this->count),
        );
    }
}
```

It works anywhere a `Filter` does, including inside `Filter::and()` and in write conditions:

```php
new QueryInput(
    OrderEntity::class,
    Filter::equals('customerId', 'c-42'),
    filter: Filter::and(Filter::equals('status', OrderStatus::Open), new SizeGreaterThanFilter('tags', 2)),
);
```

- `path($fieldName)` registers a field or a path such as `meta.carrier` and returns its placeholder. It
  throws `UnknownFieldException` for a field the entity doesn't have.
- `value($fieldName, $value)` serializes a value with that field's serializer. Pass
  `useValueFieldDefinition: true` to serialize a single element of a list or map field instead.
- `number($number)` registers a plain number, for operands that are numbers whatever the field's type,
  such as the result of `size()`.
- Return `null` to add nothing, for example for an optional criterion. Register no names or values in that case.
- If the fragment joins several clauses with `AND` or `OR`, wrap it in parentheses, as `(#a = :a OR #b = :b)`, so
  an enclosing `and()` or `not()` keeps its meaning. Leave them out of a filter meant as a whole key condition, which
  DynamoDB may refuse in parentheses.
- To test it on its own, compile it with `Test\CompiledExpression`, see
  [A filter or update action of your own](testing.md#a-filter-or-update-action-of-your-own).

## An update action of your own

[`Update`](writes.md#update-expressions) covers DynamoDB's update actions and functions. Anything else its update
syntax allows can implement `UpdateActionInterface`: `getClause()` names the clause the action belongs to, and
`compile()` returns its fragment without the clause keyword, using the same context as a filter.

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

`Update::with()` takes it alongside the built-in ones:

```php
// DynamoDB evaluates every operand against the stored item, so the copy keeps the status before the change
$this->client->update(new UpdateInput(
    $order,
    Update::with(
        Update::set('status', OrderStatus::Cancelled),
        new CopyAction('status', 'meta.previousStatus'),
    ),
));
```

- The clauses are written once each, in the order `SET`, `REMOVE`, `ADD`, `DELETE`. Fragments of one clause are
  joined with commas.
- Return `null` to add nothing. An update whose fields and actions all add nothing throws `UpdateEmptyException`.
- An action's result is computed by DynamoDB, so an entity updated in a transaction is read back afterwards (see
  [Keeping the entity in sync](writes.md#keeping-the-entity-in-sync)).
- An action whose input is a value of its field, as `setIfNotExists()` stores one, implements
  `NormalizableUpdateActionInterface` instead. The entity's normalizer then sees that input under `getPath()`,
  like a field the update sets, and the action takes back what it leaves through `withValue()`:

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
          // A copy without a fallback would fail for an item that has no `from`.
          if ($this->fallback === null) {
              return null;
          }

          return \sprintf(
              '%s = if_not_exists(%s, %s)',
              $context->path($this->to),
              $context->path($this->from),
              $context->value($this->to, $this->fallback),
          );
      }
  }
  ```

  `withValue()` only rebuilds the action. It gets `null` where the normalizer removed the value, and `compile()`
  decides what that writes, returning `null` for nothing. A value of the wrong type fails in `value()`, whose
  field serializer refuses it with a `WrongTypeException`. Where the normalizer leaves the path out, the action is
  dropped from the update.
- A path may carry one value per update. Another action or a field giving the same path a value throws
  `UpdateDuplicatePathException`.
- An operand that is no value of the field, such as a step, elements added to a set, or another path as
  `CopyAction` copies, stays as given, and the action implements `UpdateActionInterface` alone.
- To test an action on its own, compile it with `Test\CompiledExpression::ofUpdate()`, see
  [A filter or update action of your own](testing.md#a-filter-or-update-action-of-your-own).
