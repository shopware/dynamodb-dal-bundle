# Custom types, normalizers and filters

- [Custom types, normalizers and filters](#custom-types-normalizers-and-filters)
  - [A field type of your own](#a-field-type-of-your-own)
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
- `supports()` runs for every field while the container is built. Claim only your own type.
- Filters and conditions on the field serialize their values with this serializer too, for example
  `Filter::equals('total', new Money(1999, 'EUR'))`.
- Throw `WrongTypeException` or `MissingAttributeValueException` so the error names the field. The bundle wraps
  any other exception in a `FieldSerializationException` or `FieldDeserializationException` that names the
  field.

## A normalizer

A normalizer sees all of an item's fields together: before they are serialized, and after they are
deserialized. It fills in what no single field can: a key composed from other fields, generated values, and
fields that older rows lack.

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
     * Added later; rows written before have none
     */
    #[Field]
    public string $source;
}
```

```php
namespace App\Entity;

use Shopware\DynamodbDalBundle\Serializer\AbstractNormalizer;
use Symfony\Component\Uid\Uuid;

final class LedgerEntryNormalizer extends AbstractNormalizer
{
    public function normalize(array $fields, array $keys): array
    {
        if (isset($keys['id'])) {
            $fields['id'] ??= Uuid::v7();
        }

        if (isset($keys['createdAt'])) {
            $fields['createdAt'] ??= new \DateTimeImmutable();
        }

        if (isset($keys['pk'], $fields['accountId'], $fields['currency'])) {
            $fields['pk'] ??= \sprintf('%s#%s', $fields['accountId'], $fields['currency']);
        }

        return $fields;
    }

    public function denormalize(array $fields, array $keys): array
    {
        if (isset($keys['source'])) {
            $fields['source'] ??= 'legacy';
        }

        return $fields;
    }
}
```

```php
$entry = new LedgerEntryEntity();
$entry->accountId = 'acc-7';
$entry->currency = 'EUR';
$entry->amountCents = -1_250;
$entry->source = 'checkout';

$this->client->put($definition, new PutInput($entry));

$entry->pk; // "acc-7#EUR"
$entry->id; // the generated Uuid
```

- `#[Table(normalizer: ...)]` takes a service ID. In a standard Symfony application, that is the class name.
- The normalizer also runs for partial data. An update passes only the fields it writes, and a key lookup passes
  only the key fields. `$keys` lists the fields being processed. Check it, and never assume a field is present.
- Uninitialized properties reach `normalize()` as `null`. Missing attributes reach `denormalize()` as the
  field's default, or as `null` if it has none. A value the normalizer leaves `null` on a required field fails
  as usual.
- After a put, generated values are written back to the entity.
- A table key field may be nullable only on an entity with a normalizer, which then has to fill it in.

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
