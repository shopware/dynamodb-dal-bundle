<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Client\Cursor;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Client\Cursor\Cursor;
use Shopware\DynamodbDalBundle\Client\Cursor\CursorCollection;
use Shopware\DynamodbDalBundle\Client\Cursor\CursorHistory;
use Shopware\DynamodbDalBundle\Client\Cursor\CursorNormalizer;
use Shopware\DynamodbDalBundle\Client\Index;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\EntityDefinitionRegistry;
use Shopware\DynamodbDalBundle\Definition\FieldDefinition;
use Shopware\DynamodbDalBundle\Definition\IndexSchema;
use Shopware\DynamodbDalBundle\Definition\KeySchema;
use Shopware\DynamodbDalBundle\Exception\EntityDefinitionException;
use Shopware\DynamodbDalBundle\Serializer\Field\DateTimeFieldSerializer;
use Shopware\DynamodbDalBundle\Serializer\Field\StringFieldSerializer;
use Shopware\DynamodbDalBundle\Serializer\Serializer;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\NormalEntity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Exception\InvalidArgumentException;

/**
 * The {@see CursorNormalizer} is the HTTP-edge boundary: it turns a {@see Cursor} (deserialized values)
 * into a URL-safe scalar shape and back, delegating value conversion to the DAL {@see Serializer}.
 */
#[CoversClass(CursorNormalizer::class)]
class CursorNormalizerTest extends TestCase
{
    private CursorNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new CursorNormalizer(
            new Serializer(),
            new EntityDefinitionRegistry(['order' => $this->definition()]),
        );
    }

    public function testRoundTripsAGsiCursorPreservingDeserializedValues(): void
    {
        $createdAt = new \DateTimeImmutable('2026-06-02T10:00:00+00:00');
        $cursor = new Cursor(
            'order',
            new Index('tenant-1'),
            new Index('waiting', $createdAt, 'statusCreatedAtIndex'),
        );

        $normalized = $this->normalizer->normalize($cursor);

        // Wire form is scalar-only: the DateTimeImmutable became a `{N: <timestamp>}` map, never an object.
        static::assertSame('order', $normalized['table']);
        static::assertSame(['tenantId' => ['S' => 'tenant-1']], $normalized['primary']);
        static::assertSame('statusCreatedAtIndex', $normalized['index']['name'] ?? null);
        static::assertSame('waiting', $normalized['index']['status']['S'] ?? null);
        static::assertSame((string) $createdAt->getTimestamp(), $normalized['index']['createdAt']['N'] ?? null);

        $restored = $this->normalizer->denormalize($normalized, Cursor::class);

        static::assertSame('order', $restored->table);
        static::assertSame('tenant-1', $restored->primaryKey->hashValue);
        static::assertNull($restored->primaryKey->rangeValue);
        static::assertNotNull($restored->indexKey);
        static::assertSame('statusCreatedAtIndex', $restored->indexKey->index);
        static::assertSame('waiting', $restored->indexKey->hashValue);
        // The createdAt is deserialized back into a DateTimeImmutable equal to the original.
        static::assertInstanceOf(\DateTimeImmutable::class, $restored->indexKey->rangeValue);
        static::assertEquals($createdAt, $restored->indexKey->rangeValue);
    }

    public function testRoundTripsABaseTableCursorWithoutAnIndexKey(): void
    {
        $cursor = new Cursor('order', new Index('tenant-9'));

        $normalized = $this->normalizer->normalize($cursor);
        static::assertNull($normalized['index']);

        $restored = $this->normalizer->denormalize($normalized, Cursor::class);

        static::assertSame('tenant-9', $restored->primaryKey->hashValue);
        static::assertNull($restored->indexKey);
    }

    public function testRoundTripsACursorCollection(): void
    {
        $collection = new CursorCollection([
            'waiting' => new Cursor('order', new Index('tenant-1')),
            'approved' => new Cursor('order', new Index('tenant-2')),
        ]);

        $normalized = $this->normalizer->normalize($collection);

        static::assertSame(['tenantId' => ['S' => 'tenant-1']], $normalized['cursors']['waiting']['primary']);
        static::assertSame(['tenantId' => ['S' => 'tenant-2']], $normalized['cursors']['approved']['primary']);

        $restored = $this->normalizer->denormalize($normalized, CursorCollection::class);

        static::assertInstanceOf(CursorCollection::class, $restored);
        static::assertSame('tenant-1', $restored->get('waiting')?->primaryKey->hashValue);
        static::assertSame('tenant-2', $restored->get('approved')?->primaryKey->hashValue);
    }

    public function testRoundTripsACursorHistory(): void
    {
        $history = new CursorHistory([
            new CursorCollection(['waiting' => new Cursor('order', new Index('page-1'))]),
            new CursorCollection(['waiting' => new Cursor('order', new Index('page-2'))]),
        ]);

        $normalized = $this->normalizer->normalize($history);

        static::assertCount(2, $normalized['pages']);

        $restored = $this->normalizer->denormalize($normalized, CursorHistory::class);

        static::assertInstanceOf(CursorHistory::class, $restored);
        static::assertCount(2, $restored->pages);
        static::assertSame('page-1', $restored->pages[0]->get('waiting')?->primaryKey->hashValue);
        static::assertSame('page-2', $restored->pages[1]->get('waiting')?->primaryKey->hashValue);
    }

    public function testDenormalizesAHistoryFromANestedJsonString(): void
    {
        // The URL carries the whole history as a JSON string; the normalizer decodes it before denormalizing.
        $history = new CursorHistory([
            new CursorCollection(['waiting' => new Cursor('order', new Index('page-1'))]),
        ]);
        $json = json_encode($this->normalizer->normalize($history), \JSON_THROW_ON_ERROR);

        $restored = $this->normalizer->denormalize($json, CursorHistory::class);

        static::assertInstanceOf(CursorHistory::class, $restored);
        static::assertCount(1, $restored->pages);
        static::assertSame('page-1', $restored->pages[0]->get('waiting')?->primaryKey->hashValue);
    }

    public function testDenormalizesEmptyHistoryAndCollectionWhenPartsMissing(): void
    {
        $history = $this->normalizer->denormalize([], CursorHistory::class);
        static::assertInstanceOf(CursorHistory::class, $history);
        static::assertSame([], $history->pages);

        $collection = $this->normalizer->denormalize([], CursorCollection::class);
        static::assertInstanceOf(CursorCollection::class, $collection);
        static::assertTrue($collection->isEmpty());
    }

    public function testDenormalizeDropsCursorsWithNonStringNames(): void
    {
        $restored = $this->normalizer->denormalize(
            ['cursors' => [['table' => 'order', 'primary' => ['tenantId' => ['S' => 'tenant-1']], 'index' => null]]],
            CursorCollection::class,
        );

        static::assertInstanceOf(CursorCollection::class, $restored);
        static::assertTrue($restored->isEmpty());
    }

    public function testDenormalizeDropsAnUnknownIndex(): void
    {
        // The index name is not declared on the definition, so no index key is rebuilt.
        $restored = $this->normalizer->denormalize([
            'table' => 'order',
            'primary' => ['tenantId' => ['S' => 'tenant-1']],
            'index' => ['name' => 'unknownIndex', 'status' => ['S' => 'waiting']],
        ], Cursor::class);

        static::assertInstanceOf(Cursor::class, $restored);
        static::assertSame('tenant-1', $restored->primaryKey->hashValue);
        static::assertNull($restored->indexKey);
    }

    public function testDenormalizeThrowsWhenDataIsNeitherArrayNorJson(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->normalizer->denormalize('not-json', Cursor::class);
    }

    public function testDenormalizeThrowsWhenTableMissing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('table');

        $this->normalizer->denormalize(['primary' => ['tenantId' => ['S' => 'tenant-1']]], Cursor::class);
    }

    public function testDenormalizeThrowsWhenTableIsNotAString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('table');

        $this->normalizer->denormalize(['table' => 123, 'primary' => ['tenantId' => ['S' => 'tenant-1']]], Cursor::class);
    }

    public function testDenormalizeThrowsForAnUnknownTable(): void
    {
        // A table not in the registry is a misuse the registry surfaces as its own exception.
        $this->expectException(EntityDefinitionException::class);

        $this->normalizer->denormalize(['table' => 'unknown-table', 'primary' => ['tenantId' => ['S' => 'tenant-1']]], Cursor::class);
    }

    public function testNormalizeThrowsForAnUnknownTable(): void
    {
        $this->expectException(EntityDefinitionException::class);

        $this->normalizer->normalize(new Cursor('unknown-table', new Index('tenant-1')));
    }

    public function testDenormalizeIgnoresAKnownIndexWithMalformedFields(): void
    {
        // The index name is declared, but its key fields are not a {field: {S|N|B}} map — scalarFields()
        // drops the non-array entries, leaving an index key with null values rather than throwing.
        $restored = $this->normalizer->denormalize([
            'table' => 'order',
            'primary' => ['tenantId' => ['S' => 'tenant-1']],
            'index' => ['name' => 'statusCreatedAtIndex', 'status' => 'not-a-scalar-map'],
        ], Cursor::class);

        static::assertInstanceOf(Cursor::class, $restored);
        static::assertNotNull($restored->indexKey);
        static::assertSame('statusCreatedAtIndex', $restored->indexKey->index);
        static::assertNull($restored->indexKey->hashValue);
        static::assertNull($restored->indexKey->rangeValue);
    }

    public function testDenormalizeTreatsNonArrayPagesAndCursorsAsEmpty(): void
    {
        $history = $this->normalizer->denormalize(['pages' => 'not-an-array'], CursorHistory::class);
        static::assertInstanceOf(CursorHistory::class, $history);
        static::assertSame([], $history->pages);

        $collection = $this->normalizer->denormalize(['cursors' => 'not-an-array'], CursorCollection::class);
        static::assertInstanceOf(CursorCollection::class, $collection);
        static::assertTrue($collection->isEmpty());
    }

    public function testDenormalizeIgnoresAPrimaryKeyThatIsNotAScalarMap(): void
    {
        // A primary section that is not a {field: {S|N|B}} map yields an Index with null values rather
        // than throwing at the edge.
        $restored = $this->normalizer->denormalize([
            'table' => 'order',
            'primary' => 'not-a-scalar-map',
            'index' => null,
        ], Cursor::class);

        static::assertInstanceOf(Cursor::class, $restored);
        static::assertNull($restored->primaryKey->hashValue);
    }

    public function testDenormalizeDecodesAPerPageNestedJsonString(): void
    {
        // The wrapper is an array, but one page arrives as a JSON string — decodeIfJson() must decode it
        // recursively as the history is rebuilt.
        $pageJson = json_encode(
            $this->normalizer->normalize(new CursorCollection(['waiting' => new Cursor('order', new Index('page-1'))])),
            \JSON_THROW_ON_ERROR,
        );

        $restored = $this->normalizer->denormalize(['pages' => [$pageJson]], CursorHistory::class);

        static::assertInstanceOf(CursorHistory::class, $restored);
        static::assertCount(1, $restored->pages);
        static::assertSame('page-1', $restored->pages[0]->get('waiting')?->primaryKey->hashValue);
    }

    public function testNormalizeThrowsForUnsupportedObject(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->normalizer->normalize(new \stdClass());
    }

    public function testSupportsOnlyCursorTypes(): void
    {
        static::assertTrue($this->normalizer->supportsNormalization(new Cursor('order', new Index('x'))));
        static::assertTrue($this->normalizer->supportsNormalization(new CursorCollection()));
        static::assertTrue($this->normalizer->supportsNormalization(new CursorHistory()));
        static::assertFalse($this->normalizer->supportsNormalization(new \stdClass()));

        static::assertTrue($this->normalizer->supportsDenormalization([], Cursor::class));
        static::assertTrue($this->normalizer->supportsDenormalization([], CursorCollection::class));
        static::assertTrue($this->normalizer->supportsDenormalization([], CursorHistory::class));
        static::assertFalse($this->normalizer->supportsDenormalization([], \stdClass::class));
    }

    public function testGetSupportedTypes(): void
    {
        static::assertSame([
            Cursor::class => true,
            CursorCollection::class => true,
            CursorHistory::class => true,
        ], $this->normalizer->getSupportedTypes(null));
    }

    /**
     * @return EntityDefinition<AbstractEntity>
     */
    private function definition(): EntityDefinition
    {
        $string = new StringFieldSerializer();
        $dateTime = new DateTimeFieldSerializer();

        /** @var EntityDefinition<AbstractEntity> $definition */
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

        foreach ($definition->getFieldDefinitions() as $fieldDefinition) {
            $fieldDefinition->setEntityDefinition($definition);
        }

        return $definition;
    }
}
