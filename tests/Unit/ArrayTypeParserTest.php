<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit;

use Shopware\DynamodbDalBundle\ArrayTypeParser;
use Shopware\DynamodbDalBundle\Tests\Unit\Fixtures\ArrayTypeParserFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ArrayTypeParser::class)]
class ArrayTypeParserTest extends TestCase
{
    public function testGetDocblockVarTypeReturnsNullWhenNoDocblock(): void
    {
        $property = new \ReflectionProperty(ArrayTypeParserFixture::class, 'noDocblock');
        static::assertNull(ArrayTypeParser::getDocblockVarType($property));
    }

    public function testGetDocblockVarTypeReturnsTypeFromVar(): void
    {
        $property = new \ReflectionProperty(ArrayTypeParserFixture::class, 'listType');
        static::assertSame('list<string>', ArrayTypeParser::getDocblockVarType($property));
    }

    public function testGetDocblockVarTypeReturnsMapType(): void
    {
        $property = new \ReflectionProperty(ArrayTypeParserFixture::class, 'mapType');
        static::assertSame('array<string, int>', ArrayTypeParser::getDocblockVarType($property));
    }

    public function testIsListType(): void
    {
        static::assertTrue(ArrayTypeParser::isListType('list<string>'));
        static::assertTrue(ArrayTypeParser::isListType('list<int>'));
        static::assertTrue(ArrayTypeParser::isListType('list<list<string>>'));
        static::assertFalse(ArrayTypeParser::isListType('array<string, int>'));
        static::assertFalse(ArrayTypeParser::isListType('array<string>'));
        static::assertFalse(ArrayTypeParser::isListType('array<int, string>'));
        static::assertFalse(ArrayTypeParser::isListType('string'));
    }

    public function testIsMapType(): void
    {
        static::assertTrue(ArrayTypeParser::isMapType('array<string, string>'));
        static::assertTrue(ArrayTypeParser::isMapType('array<string,int>'));
        static::assertTrue(ArrayTypeParser::isMapType('array<string, list<int>>'));
        static::assertTrue(ArrayTypeParser::isMapType('array<int, string>'));
        static::assertTrue(ArrayTypeParser::isMapType('array<integer, string>'));
        static::assertTrue(ArrayTypeParser::isMapType('array<string>'));
        static::assertFalse(ArrayTypeParser::isMapType('list<string>'));
    }

    public function testIsListOrMapType(): void
    {
        static::assertTrue(ArrayTypeParser::isListOrMapType('list<string>'));
        static::assertTrue(ArrayTypeParser::isListOrMapType('array<string, int>'));
        static::assertTrue(ArrayTypeParser::isListOrMapType('array<string>'));
        static::assertFalse(ArrayTypeParser::isListOrMapType('string'));
        static::assertFalse(ArrayTypeParser::isListOrMapType('int'));
    }

    public function testExtractValueTypeFromList(): void
    {
        static::assertSame('string', ArrayTypeParser::extractValueType('list<string>'));
        static::assertSame('int', ArrayTypeParser::extractValueType('list<int>'));
        static::assertSame('list<string>', ArrayTypeParser::extractValueType('list<list<string>>'));
    }

    public function testExtractValueTypeFromMap(): void
    {
        static::assertSame('int', ArrayTypeParser::extractValueType('array<string, int>'));
        static::assertSame('string', ArrayTypeParser::extractValueType('array<string, string>'));
        static::assertSame('list<int>', ArrayTypeParser::extractValueType('array<string, list<int>>'));
        static::assertSame('string', ArrayTypeParser::extractValueType('array<array-key, string>'));
        static::assertSame('string', ArrayTypeParser::extractValueType('array<int, string>'));
        static::assertSame('bool', ArrayTypeParser::extractValueType('array<integer, bool>'));
    }

    public function testExtractValueTypeFromSingleParamArray(): void
    {
        static::assertSame('string', ArrayTypeParser::extractValueType('array<string>'));
        static::assertSame('int', ArrayTypeParser::extractValueType('array<int>'));
        static::assertSame('list<string>', ArrayTypeParser::extractValueType('array<list<string>>'));
    }

    public function testExtractValueTypeReturnsNullForNonListNonMap(): void
    {
        static::assertNull(ArrayTypeParser::extractValueType('string'));
        static::assertNull(ArrayTypeParser::extractValueType('int'));
    }

    public function testExtractValueTypeTrimsWhitespace(): void
    {
        static::assertSame('string', ArrayTypeParser::extractValueType('list< string >'));
        static::assertSame('int', ArrayTypeParser::extractValueType('array<string , int>'));
    }
}
