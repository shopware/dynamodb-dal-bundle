# Custom types, normalizers and filters

- [Custom types, normalizers and filters](#custom-types-normalizers-and-filters)
  - [A field type of your own](#a-field-type-of-your-own)
  - [A `JsonSerializable` value object](#a-jsonserializable-value-object)
  - [A normalizer](#a-normalizer)
  - [A filter of your own](#a-filter-of-your-own)

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

$this->client->put(LedgerEntryEntity::class, new PutInput($entry));

$entry->pk; // "acc-7#EUR"
$entry->id; // the generated Uuid

$this->client->update(LedgerEntryEntity::class, new UpdateInput($entry, ['amountCents' => -1_300]));

$entry->updatedAt; // stamped without the caller naming it
```

`$context->operation` says what the normalizer runs for, and which fields it gets:

| Operation | Runs for | Fields present |
|---|---|---|
| `Put` | A put | Every field, `null` where the property is not initialized |
| `Update` | An update, whose row is known to exist | Only the paths the update writes, `null` for one it removes |
| `Key` | A lookup, delete or update by key, and a key read from a pagination cursor | Only the key fields |
| `Read` | An item read from DynamoDB | Every field, as its default or `null` where the row has none |

- `#[Table(normalizer: ...)]` takes a service ID. In a standard Symfony application, that is the class name.
- Override only the side you need. The other one leaves the fields as they are.
- Never assume a field is present. `has()` tells whether it is, even as `null`, and `get()` returns `null` for
  one that is absent or not present.
- An update may write into an attribute without naming it, such as `meta.kind` instead of `meta`.
  `hasWithin('meta')` and `getWithin('meta')` find the attribute and every path nested under it,
  but not `metadata`.
- `set()` adds or replaces a path. `remove()` makes it absent: a put leaves the attribute out, an update removes
  it. `omit()` leaves it out entirely: an update does not touch it, a read does not apply it to the entity.
- `setIfUnset()` fills a path only where it is present and `null`, such as a generated ID on a put. A path the
  fields do not name stays out, so an update that does not write `createdAt` never overwrites the stored one, and a
  key never gains an attribute. Use `set()` to add a path, such as `updatedAt` on an update.
- A key runs through the normalizer on its own, also an update's, whose fields run as `Update` separately.
  Canonicalize a key there, such as trimming or lowercasing it, so a lookup matches the stored row. Generate
  nothing for it.
- A value the normalizer leaves `null` on a required field fails as usual.
- After a put, generated values are written back to the entity. After an update in a transaction, which returns
  no item, the fields it wrote are, including those the normalizer added. `denormalize()` then runs with the
  write's operation, `Put` or `Update`, instead of `Read`.
- A table key field may be nullable only on an entity with a normalizer, which then has to fill it in.
- A test builds the context the normalizer should handle and reads the fields back:

  ```php
  $context = new NormalizerContext(NormalizerOperation::Update, ['amountCents' => -1_300]);

  new LedgerEntryNormalizer()->normalize($context);

  static::assertInstanceOf(\DateTimeImmutable::class, $context->get('updatedAt'));
  static::assertFalse($context->has('createdAt'));
  ```

## A filter of your own

`Filter` covers DynamoDB's comparisons and functions. Anything else can implement `ExpressionInterface`: its
`compile()` returns an expression fragment and registers the attribute names and values it uses on the context.

```php
namespace App\Dal;

use Shopware\DynamodbDalBundle\Expression\Contract\ExpressionInterface;
use Shopware\DynamodbDalBundle\Expression\ExpressionCompileContext;

/**
 * Matches a list or map with more than `$count` elements, or a string longer than `$count` characters.
 */
final readonly class SizeGreaterThanFilter implements ExpressionInterface
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
            $context->attribute($this->fieldName),
            $context->numberPlaceholder($this->count),
        );
    }
}
```

It works anywhere a `Filter` does, including inside `Filter::and()` and in write conditions:

```php
new QueryInput(
    Filter::equals('customerId', 'c-42'),
    filter: Filter::and(Filter::equals('status', OrderStatus::Open), new SizeGreaterThanFilter('tags', 2)),
);
```

- `attribute($fieldName)` registers a field or a path such as `meta.carrier` and returns its placeholder. It
  throws `UnknownFieldException` for a field the entity doesn't have.
- `placeholder($fieldName, $value)` serializes a value with that field's serializer. Pass
  `useValueFieldDefinition: true` to serialize a single element of a list or map field instead.
- `numberPlaceholder($number)` registers a plain number, for operands that are numbers whatever the field's type,
  such as the result of `size()`.
- Return `null` to add nothing, for example for an optional criterion. Register no names or values in that case.
- If the fragment joins several clauses with `AND` or `OR`, set `$context->isCompound = true`. An enclosing
  `and()` or `or()` then wraps it in parentheses.
