<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Serializer;

use AsyncAws\DynamoDb\ValueObject\AttributeValue;

/**
 * A key attribute's value as a string that two values share exactly where the bundle takes them for the same key.
 *
 * DynamoDB stores a number in a form of its own, without leading or trailing zeros, so it returns `10.5` for a key
 * written as `10.50`, and a float serializer writes `1.0e+17` for what DynamoDB returns as `100000000000000000`. A
 * number is read as PHP reads it, as the field serializers read it into an entity. Two numbers beyond what an int or
 * a float holds, which DynamoDB tells apart, can therefore be one key here.
 *
 * @internal
 */
final class CanonicalKeyValue
{
    /**
     * Its type, `S:`, `N:` or `B:`, then the value, a number in the form of {@see number()}.
     */
    public static function of(AttributeValue $value): string
    {
        return match (true) {
            $value->getS() !== null => 'S:' . $value->getS(),
            // One that does not parse is kept as written, for DynamoDB to refuse
            $value->getN() !== null => 'N:' . (self::number($value->getN()) ?? $value->getN()),
            $value->getB() !== null => 'B:' . $value->getB(),
            // A key attribute is only ever a string, number or binary, so this arm is only defensive.
            default => '',
        };
    }

    /**
     * The number as PHP reads it, written out in full, such as `10.5` for `10.50` and `0` for `-0.00`. `null` for a
     * string that PHP takes for no number.
     */
    public static function number(string $number): ?string
    {
        if (!is_numeric($number)) {
            return null;
        }

        // An int where it is one and fits, a float otherwise
        $value = +$number;

        // A float that holds a whole number is the int it holds, since DynamoDB returns `1.0e+17` as `100000000000000000`
        if (\is_float($value) && floor($value) === $value && abs($value) < 2 ** 63) {
            $value = (int) $value;
        }

        // Unlike a cast to string, which rounds a float to the `precision` setting, this tells every float apart
        return var_export($value, true);
    }
}
