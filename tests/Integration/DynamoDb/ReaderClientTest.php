<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Integration\DynamoDb;

use PHPUnit\Framework\Attributes\CoversClass;
use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Client\Index;
use Shopware\DynamodbDalBundle\Client\Input\GetInput;
use Shopware\DynamodbDalBundle\Client\Input\PutInput;
use Shopware\DynamodbDalBundle\Client\Input\QueryInput;
use Shopware\DynamodbDalBundle\Client\Input\ScanInput;
use Shopware\DynamodbDalBundle\Client\ReaderClient;
use Shopware\DynamodbDalBundle\Criteria\Filter;
use Shopware\DynamodbDalBundle\Exception\UnknownEntityDefinitionException;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\Entity\ArchiveEntity;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\Entity\RecordEntity;

/**
 * The read engine against real tables — how a key read splits into `GetItem` and `BatchGetItem`, how a
 * scan and a query are shaped, and where the reader paginates on its own.
 */
#[CoversClass(ReaderClient::class)]
class ReaderClientTest extends DynamoDbTestCase
{
    public function testGetWithASingleKeyDoesAGetItem(): void
    {
        $this->seed(RecordEntity::create(self::TENANT, 'a', name: 'only'));

        $entities = iterator_to_array($this->reader()->get(new GetInput([
            RecordEntity::class => [new Index(self::TENANT, 'a')],
        ])), false);

        static::assertCount(1, $entities);
        static::assertInstanceOf(RecordEntity::class, $entities[0]);
        static::assertSame('only', $entities[0]->name);
    }

    public function testGetWithAMissingKeyYieldsNothing(): void
    {
        $entities = iterator_to_array($this->reader()->get(new GetInput([
            RecordEntity::class => [new Index(self::TENANT, 'absent')],
        ])), false);

        static::assertSame([], $entities);
    }

    public function testGetWithSeveralKeysDoesABatchGetItem(): void
    {
        foreach (['a', 'b', 'c'] as $id) {
            $this->seed(RecordEntity::create(self::TENANT, $id));
        }

        $entities = iterator_to_array($this->reader()->get(new GetInput([
            RecordEntity::class => [new Index(self::TENANT, 'a'), new Index(self::TENANT, 'b')],
        ])), false);

        static::assertSame(['a', 'b'], $this->sortedIds($entities));
    }

    public function testGetReadsKeysSpanningSeveralEntityClasses(): void
    {
        $this->seed(RecordEntity::create(self::TENANT, 'a'));
        $this->seed(ArchiveEntity::create('arch-1', 'kept'), 'archive');

        $entities = iterator_to_array($this->reader()->get(new GetInput([
            RecordEntity::class => [new Index(self::TENANT, 'a')],
            ArchiveEntity::class => [new Index('arch-1')],
        ])), false);

        $classes = array_map(static fn (AbstractEntity $entity): string => $entity::class, $entities);
        sort($classes);

        static::assertSame([ArchiveEntity::class, RecordEntity::class], $classes);
    }

    /**
     * `BatchGetItem` caps at 100 keys, so a larger read has to be chunked — and every chunk's items have
     * to come back, in one flat stream.
     */
    public function testGetChunksMoreThan100KeysAcrossSeveralBatchGetItemCalls(): void
    {
        $keys = [];
        for ($i = 0; $i < 120; ++$i) {
            $id = \sprintf('id-%03d', $i);
            $this->seed(RecordEntity::create(self::TENANT, $id));
            $keys[] = new Index(self::TENANT, $id);
        }

        $entities = iterator_to_array($this->reader()->get(new GetInput([RecordEntity::class => $keys])), false);

        static::assertCount(120, $entities);
        static::assertCount(120, array_unique($this->sortedIds($entities)));
    }

    public function testGetThrowsForAnUnregisteredEntityClass(): void
    {
        static::expectException(UnknownEntityDefinitionException::class);

        /** @phpstan-ignore-next-line argument.type -- deliberately not an entity of this application */
        iterator_to_array($this->reader()->get(new GetInput([\stdClass::class => [new Index('x')]])), false);
    }

    public function testGetWithConsistentReadIssuesAStronglyConsistentRead(): void
    {
        $this->seed(RecordEntity::create(self::TENANT, 'a', name: 'strong'));

        $entities = iterator_to_array($this->reader()->get(new GetInput([
            RecordEntity::class => [new Index(self::TENANT, 'a')],
        ], true)), false);

        static::assertCount(1, $entities);
        static::assertInstanceOf(RecordEntity::class, $entities[0]);
        static::assertSame('strong', $entities[0]->name);
    }

    public function testSearchReturnsNothingWhenNoMatch(): void
    {
        $entities = iterator_to_array($this->reader()->search($this->definition('record'), new ScanInput(
            Filter::equals('name', 'absent'),
        )), false);

        static::assertSame([], $entities);
    }

    public function testCountReturnsZeroWhenNoMatch(): void
    {
        static::assertSame(0, $this->reader()->count($this->definition('record'), new ScanInput(Filter::equals('name', 'absent'))));
    }

    public function testSearchScansWithAFilter(): void
    {
        $this->seed(RecordEntity::create(self::TENANT, 'a', name: 'match'));
        $this->seed(RecordEntity::create(self::TENANT, 'b', name: 'other'));
        $this->seed(RecordEntity::create('tenant-2', 'c', name: 'match'));

        $entities = iterator_to_array($this->reader()->search($this->definition('record'), new ScanInput(
            Filter::equals('name', 'match'),
        )), false);

        static::assertSame(['a', 'c'], $this->sortedIds($entities));
    }

    public function testSearchQueriesABaseTableNewestFirst(): void
    {
        $this->seed(RecordEntity::create(self::TENANT, 'a'));
        $this->seed(RecordEntity::create(self::TENANT, 'b'));
        $this->seed(RecordEntity::create(self::TENANT, 'c'));

        $entities = iterator_to_array($this->reader()->search($this->definition('record'), new QueryInput(
            Filter::equals('tenantId', self::TENANT),
            forward: false,
        )), false);

        static::assertSame(['c', 'b', 'a'], $this->ids($entities));
    }

    /**
     * DynamoDB caps a Query response at 1 MB, so a large partition comes back in several service pages.
     * The reader is handed an async-aws result that follows them, and the caller sees one stream.
     */
    public function testSearchAutoPaginatesAcrossDynamoDbServicePages(): void
    {
        $filler = str_repeat('x', 40_000);
        for ($i = 0; $i < 40; ++$i) {
            $entity = RecordEntity::create(self::TENANT, \sprintf('id-%02d', $i));
            $entity->name = $filler;
            $this->seed($entity);
        }

        $entities = iterator_to_array($this->reader()->search($this->definition('record'), new QueryInput(
            Filter::equals('tenantId', self::TENANT),
        )), false);

        static::assertCount(40, $entities);
    }

    public function testCountSumsMatches(): void
    {
        foreach (['a', 'b', 'c'] as $id) {
            $this->seed(RecordEntity::create(self::TENANT, $id));
        }
        $this->seed(RecordEntity::create('tenant-2', 'd'));

        static::assertSame(4, $this->reader()->count($this->definition('record'), new ScanInput()));
        static::assertSame(3, $this->reader()->count($this->definition('record'), new QueryInput(Filter::equals('tenantId', self::TENANT))));
    }

    private function reader(): ReaderClient
    {
        $reader = $this->container()->get('test.' . ReaderClient::class);
        static::assertInstanceOf(ReaderClient::class, $reader);

        return $reader;
    }

    private function seed(AbstractEntity $entity, string $definition = 'record'): void
    {
        $this->client()->put($this->definition($definition), new PutInput($entity));
    }

    /**
     * @param array<AbstractEntity> $entities
     *
     * @return list<string>
     */
    private function ids(array $entities): array
    {
        $ids = [];
        foreach ($entities as $entity) {
            static::assertInstanceOf(RecordEntity::class, $entity);
            $ids[] = $entity->id;
        }

        return $ids;
    }

    /**
     * @param array<AbstractEntity> $entities
     *
     * @return list<string>
     */
    private function sortedIds(array $entities): array
    {
        $ids = $this->ids($entities);
        sort($ids);

        return $ids;
    }
}
