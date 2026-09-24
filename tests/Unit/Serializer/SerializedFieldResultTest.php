<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Serializer;

use Shopware\DynamodbDalBundle\Definition\FieldPath;
use Shopware\DynamodbDalBundle\Serializer\SerializedFieldResult;
use Shopware\DynamodbDalBundle\Tests\Unit\Definition\Fixtures\MapDefinition;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SerializedFieldResult::class)]
class SerializedFieldResultTest extends TestCase
{
    public function testGetters(): void
    {
        $path = $this->parse('settings');
        $attributeValue = new AttributeValue(['S' => 'test-id']);

        $result = new SerializedFieldResult($path, $attributeValue);

        static::assertSame($path->definition, $result->getDefinition());
        static::assertSame($attributeValue, $result->getValue());
        static::assertSame(['settings' => $attributeValue], $result->getFields());
    }

    public function testAnUnsetValueIsAbsentFromTheItem(): void
    {
        $result = new SerializedFieldResult($this->parse('settings'), null);

        static::assertNull($result->getValue());
        static::assertSame([], $result->getFields());
    }

    public function testNestedPathIsKeyedByThePath(): void
    {
        $attributeValue = new AttributeValue(['S' => 'token-1']);
        $result = new SerializedFieldResult($this->parse('settings.color'), $attributeValue);

        static::assertSame(['settings.color' => $attributeValue], $result->getFields());
    }

    private function parse(string $path): FieldPath
    {
        $parsed = FieldPath::tryParse(MapDefinition::create(), $path);
        static::assertNotNull($parsed);

        return $parsed;
    }
}
