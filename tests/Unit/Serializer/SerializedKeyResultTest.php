<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Serializer;

use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\FieldDefinition;
use Shopware\DynamodbDalBundle\Definition\KeySchema;
use Shopware\DynamodbDalBundle\Serializer\Field\StringFieldSerializer;
use Shopware\DynamodbDalBundle\Serializer\SerializedKeyResult;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\NormalEntity;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SerializedKeyResult::class)]
class SerializedKeyResultTest extends TestCase
{
    /**
     * A put's key comes from its whole item, so only the attributes of the key schema are the key.
     */
    public function testTheKeyOfAnItemIsTheAttributesOfTheKeySchema(): void
    {
        $key = SerializedKeyResult::fromItem($this->definition('orders'), [
            'tenantId' => new AttributeValue(['S' => 'tenant-1']),
            'name' => new AttributeValue(['S' => 'not part of the key']),
            'createdAt' => new AttributeValue(['N' => '1700000000']),
        ]);

        static::assertEquals([
            'tenantId' => new AttributeValue(['S' => 'tenant-1']),
            'createdAt' => new AttributeValue(['N' => '1700000000']),
        ], $key->fields);
    }

    public function testDifferentKeysHashApart(): void
    {
        $definition = $this->definition('orders');

        static::assertNotSame(
            SerializedKeyResult::fromItem($definition, $this->item('tenant-1', '1700000000'))->hash,
            SerializedKeyResult::fromItem($definition, $this->item('tenant-2', '1700000000'))->hash,
        );
    }

    /**
     * A composite key hashes both halves, so two items sharing a hash key stay distinct.
     */
    public function testTheRangeKeyIsPartOfTheHash(): void
    {
        $definition = $this->definition('orders');

        static::assertNotSame(
            SerializedKeyResult::fromItem($definition, $this->item('tenant-1', '1700000000'))->hash,
            SerializedKeyResult::fromItem($definition, $this->item('tenant-1', '1700000001'))->hash,
        );
    }

    /**
     * A key names an item of its table only, so the same key on another table names another item.
     */
    public function testTheSameKeyOnAnotherTableHashesApart(): void
    {
        $item = $this->item('tenant-1', '1700000000');

        static::assertNotSame(
            SerializedKeyResult::fromItem($this->definition('orders'), $item)->hash,
            SerializedKeyResult::fromItem($this->definition('archive'), $item)->hash,
        );
    }

    /**
     * DynamoDB returns a number key in a form of its own, such as `10.5` for `10.50`, so an item still finds the key it
     * was read or written by.
     */
    public function testANumberHashesAsTheNumberItIsWhateverItsForm(): void
    {
        $definition = $this->definition('orders');

        foreach ([['10.50', '10.5'], ['1.0e+20', '100000000000000000000'], ['-0', '0'], ['007', '7']] as [$written, $stored]) {
            static::assertSame(
                SerializedKeyResult::fromItem($definition, $this->item('tenant-1', $written))->hash,
                SerializedKeyResult::fromItem($definition, $this->item('tenant-1', $stored))->hash,
                "{$written} and {$stored}",
            );
        }
    }

    /**
     * A string key may hold any character, so no value can run into the one after it.
     */
    public function testNoValueRunsIntoTheNext(): void
    {
        $definition = $this->definition('orders');
        $key = static fn (string $tenantId, string $createdAt): string => SerializedKeyResult::fromItem($definition, [
            'tenantId' => new AttributeValue(['S' => $tenantId]),
            'createdAt' => new AttributeValue(['S' => $createdAt]),
        ])->hash;

        static::assertNotSame($key("x\0S:y", 'z'), $key('x', "y\0S:z"));
        static::assertNotSame($key('x1:', 'y'), $key('x', '1:y'));
    }

    /**
     * @return EntityDefinition<NormalEntity>
     */
    private function definition(string $table): EntityDefinition
    {
        $string = new StringFieldSerializer();

        /** @var EntityDefinition<NormalEntity> $definition */
        $definition = new EntityDefinition(
            $table,
            $table,
            NormalEntity::class,
            null,
            [
                'tenantId' => new FieldDefinition('tenantId', 'string', false, false, null, $string),
                'name' => new FieldDefinition('name', 'string', true, true, null, $string),
                'createdAt' => new FieldDefinition('createdAt', 'string', false, false, null, $string),
            ],
            new KeySchema('tenantId', 'createdAt'),
        );

        return $definition;
    }

    /**
     * @return array<string, AttributeValue>
     */
    private function item(string $tenantId, string $createdAt): array
    {
        return [
            'tenantId' => new AttributeValue(['S' => $tenantId]),
            'createdAt' => new AttributeValue(['N' => $createdAt]),
        ];
    }
}
