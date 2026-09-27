<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Serializer;

use Shopware\DynamodbDalBundle\Serializer\CanonicalKeyValue;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CanonicalKeyValue::class)]
class CanonicalKeyValueTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function sameNumbers(): iterable
    {
        yield 'a trailing zero of the fraction' => ['10.50', '10.5'];
        yield 'an exponent, as a float serializer writes one' => ['1.0e+20', '100000000000000000000'];
        yield 'a whole number in an exponent, where the plain form is an int' => ['1.0e+17', '100000000000000000'];
        yield 'a whole number with a fraction of zero' => ['7.0', '7'];
        yield 'a negative exponent' => ['1.0e-5', '0.00001'];
        yield 'leading zeros' => ['007', '7'];
        yield 'a point without a fraction' => ['5.', '5'];
        yield 'a fraction without an integer part' => ['.5', '0.5'];
        yield 'a plus sign' => ['+5', '5'];
        yield 'zero, of any sign or form' => ['-0.00', '0'];
    }

    #[DataProvider('sameNumbers')]
    public function testTheSameNumberHasOneForm(string $written, string $stored): void
    {
        static::assertSame(CanonicalKeyValue::number($stored), CanonicalKeyValue::number($written));
    }

    /**
     * An int where it fits, and a float beyond it, each written out in full.
     */
    public function testANumberIsWrittenOutAsPhpReadsIt(): void
    {
        static::assertSame('-10.5', CanonicalKeyValue::number('-10.50'));
        static::assertSame('100000000000000000', CanonicalKeyValue::number('100000000000000000'));
        static::assertSame('1.0E+20', CanonicalKeyValue::number('100000000000000000000'));
        static::assertSame('0.30000000000000004', CanonicalKeyValue::number('0.30000000000000004'));
        static::assertSame('0', CanonicalKeyValue::number('0'));
    }

    /**
     * An int key, such as a 64-bit id, keeps every digit, where a float would take neighbours above 2^53 for one.
     */
    public function testAnIntKeepsEveryDigit(): void
    {
        static::assertNotSame(CanonicalKeyValue::number('9007199254740993'), CanonicalKeyValue::number('9007199254740992'));
        static::assertNotSame(CanonicalKeyValue::number('9223372036854775807'), CanonicalKeyValue::number('9223372036854775806'));
    }

    public function testWhatPhpTakesForNoNumberIsNone(): void
    {
        foreach (['', '-', '.', 'abc', '1.2.3', '1e', '1 5', '0x1A', '1_000'] as $notANumber) {
            static::assertNull(CanonicalKeyValue::number($notANumber), var_export($notANumber, true));
        }
    }

    /**
     * A string that looks like a number is still a string, and it is kept as it is.
     */
    public function testAValueKeepsItsType(): void
    {
        static::assertSame('S:10.50', CanonicalKeyValue::of(new AttributeValue(['S' => '10.50'])));
        static::assertSame('N:10.5', CanonicalKeyValue::of(new AttributeValue(['N' => '10.50'])));
        static::assertSame("B:\x00\x01", CanonicalKeyValue::of(new AttributeValue(['B' => "\x00\x01"])));
        static::assertNotSame(CanonicalKeyValue::of(new AttributeValue(['S' => '1'])), CanonicalKeyValue::of(new AttributeValue(['N' => '1'])));
    }
}
