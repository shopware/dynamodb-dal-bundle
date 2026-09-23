<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit;

use Shopware\DynamodbDalBundle\Client\Cursor\Cursor;
use Shopware\DynamodbDalBundle\Client\Cursor\CursorCollection;
use Shopware\DynamodbDalBundle\Client\Index;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\FieldDefinition;
use Shopware\DynamodbDalBundle\Definition\IndexSchema;
use Shopware\DynamodbDalBundle\Definition\KeySchema;
use Shopware\DynamodbDalBundle\Serializer\Field\DateTimeFieldSerializer;
use Shopware\DynamodbDalBundle\Serializer\Field\StringFieldSerializer;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\NormalEntity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A {@see Cursor} is a resume position holding deserialized values: the base-table primary {@see Index}
 * and, for a GSI query, the index {@see Index}. {@see Cursor::from()} extracts those from a boundary
 * entity. The HTTP-edge wire form is owned by the `CursorNormalizer` and tested separately.
 */
#[CoversClass(Cursor::class)]
#[CoversClass(Index::class)]
#[CoversClass(CursorCollection::class)]
class CursorTest extends TestCase
{
    public function testCarriesTheTablePrimaryKeyAndIndexKeyAsDeserializedValues(): void
    {
        $createdAt = new \DateTimeImmutable('2026-06-02T10:00:00+00:00');
        $cursor = new Cursor(
            'order',
            new Index('tenant-1'),
            new Index('waiting', $createdAt, 'statusCreatedAtIndex'),
        );

        static::assertSame('order', $cursor->table);
        static::assertSame('tenant-1', $cursor->primaryKey->hashValue);
        static::assertNull($cursor->primaryKey->rangeValue);
        static::assertNotNull($cursor->indexKey);
        static::assertSame('statusCreatedAtIndex', $cursor->indexKey->index);
        static::assertSame('waiting', $cursor->indexKey->hashValue);
        static::assertSame($createdAt, $cursor->indexKey->rangeValue);
    }

    public function testFromExtractsTheBaseTablePrimaryKeyWithoutAnIndex(): void
    {
        $entity = new NormalEntity()->setAutofilledId('a')->setRequired('req');

        $cursor = Cursor::from(NormalEntity::createDefinition(), $entity);

        static::assertSame('normal', $cursor->table);
        static::assertSame('a', $cursor->primaryKey->hashValue);
        static::assertNull($cursor->primaryKey->rangeValue);
        static::assertNull($cursor->indexKey);
    }

    public function testFromAcceptsADeserializedFieldMap(): void
    {
        $cursor = Cursor::from(NormalEntity::createDefinition(), ['autofilledId' => 'b', 'required' => 'req']);

        static::assertSame('b', $cursor->primaryKey->hashValue);
        static::assertNull($cursor->indexKey);
    }

    public function testFromCapturesTheIndexKeyFieldsWhenIndexNamesAGsi(): void
    {
        $createdAt = new \DateTimeImmutable('2026-06-02T10:00:00+00:00');
        $cursor = Cursor::from(
            $this->statusIndexedDefinition(),
            ['tenantId' => 'tenant-1', 'status' => 'waiting', 'createdAt' => $createdAt],
            'statusCreatedAtIndex',
        );

        static::assertSame('order', $cursor->table);
        static::assertSame('tenant-1', $cursor->primaryKey->hashValue);
        static::assertNotNull($cursor->indexKey);
        static::assertSame('statusCreatedAtIndex', $cursor->indexKey->index);
        static::assertSame('waiting', $cursor->indexKey->hashValue);
        static::assertSame($createdAt, $cursor->indexKey->rangeValue);
    }

    public function testFromIgnoresAnUnknownIndexName(): void
    {
        $cursor = Cursor::from(
            $this->statusIndexedDefinition(),
            ['tenantId' => 'tenant-1', 'status' => 'waiting'],
            'noSuchIndex',
        );

        static::assertSame('tenant-1', $cursor->primaryKey->hashValue);
        static::assertNull($cursor->indexKey);
    }

    public function testCollectionKeepsNamedPositionsAndDerivesNewInstancesImmutably(): void
    {
        $waiting = new Cursor('order', new Index('tenant-1'));
        $approved = new Cursor('order', new Index('tenant-2'));

        $collection = new CursorCollection(['waiting' => $waiting]);
        static::assertFalse($collection->isEmpty());
        static::assertSame($waiting, $collection->get('waiting'));
        static::assertSame($waiting, $collection['waiting']);
        static::assertNull($collection->get('missing'));

        $derived = $collection->with('approved', $approved);
        static::assertSame($approved, $derived->get('approved'));
        static::assertNull($collection->get('approved'), 'with() must not mutate the original');

        static::assertTrue(new CursorCollection()->isEmpty());
    }

    /**
     * @return EntityDefinition<NormalEntity>
     */
    private function statusIndexedDefinition(): EntityDefinition
    {
        $string = new StringFieldSerializer();
        $dateTime = new DateTimeFieldSerializer();

        /** @var EntityDefinition<NormalEntity> $definition */
        $definition = new EntityDefinition(
            'order',
            'order',
            NormalEntity::class,
            null,
            /** @phpstan-ignore-next-line -- FieldDefinition generic is missing */
            [
                'tenantId' => new FieldDefinition('tenantId', 'string', false, false, null, $string),
                'status' => new FieldDefinition('status', 'string', false, false, null, $string),
                'createdAt' => new FieldDefinition('createdAt', \DateTimeImmutable::class, true, true, null, $dateTime),
            ],
            new KeySchema('tenantId'),
            ['statusCreatedAtIndex' => new IndexSchema('statusCreatedAtIndex', hashKey: 'status', rangeKey: 'createdAt')],
        );

        return $definition;
    }
}
