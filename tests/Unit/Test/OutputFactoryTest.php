<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Test;

use Shopware\DynamodbDalBundle\Client\Cursor;
use Shopware\DynamodbDalBundle\Client\Input\QueryInput;
use Shopware\DynamodbDalBundle\Client\Input\ScanInput;
use Shopware\DynamodbDalBundle\Client\Output\SearchOutput;
use Shopware\DynamodbDalBundle\Exception\InvalidCursorException;
use Shopware\DynamodbDalBundle\Expression\Filter;
use Shopware\DynamodbDalBundle\Test\OutputFactory;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\NormalEntity;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\OtherEntity;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OutputFactory::class)]
class OutputFactoryTest extends TestCase
{
    public function testASearchStandInPagesItsEntitiesAsTheSearchSays(): void
    {
        [$a, $b, $c] = self::entities('a', 'b', 'c');

        $page = OutputFactory::search(new ScanInput(NormalEntity::class, limit: 2), [$a, $b, $c], self::keyOf(...))->page();

        static::assertSame([$a, $b], $page->items);
        static::assertSame($page->cursorAfter($b), $page->next);
        static::assertNull($page->previous);
    }

    /**
     * A test can then compare the token its code under test passes on with one it builds the same way.
     */
    public function testASearchStandInHandsOutTheTokensARealSearchWould(): void
    {
        [$a, $b] = self::entities('a', 'b');
        $start = new Cursor(['autofilledId' => new AttributeValue(['S' => 'start'])])->encode();
        $query = new QueryInput(NormalEntity::class, Filter::equals('autofilledId', 'x'), cursor: $start, limit: 1);

        $real = new SearchOutput((static function () use ($a, $b): \Generator {
            yield ['autofilledId' => new AttributeValue(['S' => 'a'])] => $a;
            yield ['autofilledId' => new AttributeValue(['S' => 'b'])] => $b;
        })(), $query)->page();
        $standIn = OutputFactory::search($query, [$a, $b], self::keyOf(...))->page();

        static::assertNotNull($standIn->next);
        static::assertSame($real->next, $standIn->next);
        static::assertSame($real->previous, $standIn->previous);
    }

    public function testASearchStandInTakesANumberForANumberKey(): void
    {
        [$a, $b] = self::entities('a', 'b');

        $page = OutputFactory::search(new ScanInput(NormalEntity::class, limit: 1), [$a, $b], static fn (NormalEntity $entity): array => ['n' => 7])->page();

        static::assertNotNull($page->next);
        static::assertEquals(['n' => new AttributeValue(['N' => '7'])], Cursor::decode($page->next)->key);
    }

    public function testASearchStandInWithoutKeysHandsOutTokensEverySearchRefuses(): void
    {
        [$a, $b] = self::entities('a', 'b');

        $page = OutputFactory::search(new ScanInput(NormalEntity::class, limit: 1), [$a, $b])->page();

        static::assertSame([$a], $page->items);
        static::assertNotNull($page->next);

        $this->expectException(InvalidCursorException::class);
        Cursor::decode($page->next);
    }

    public function testASearchStandInStreamsOnceLikeARealOne(): void
    {
        [$a, $b, $c] = self::entities('a', 'b', 'c');

        static::assertSame($a, OutputFactory::search(new ScanInput(NormalEntity::class), [$a, $b])->first());

        $output = OutputFactory::search(new ScanInput(NormalEntity::class, limit: 2), [$a, $b, $c]);
        static::assertSame([$a, $b], $output->toArray());

        $this->expectException(\LogicException::class);
        $output->toArray();
    }

    public function testAGetStandInFindsItsEntitiesAcrossClasses(): void
    {
        [$a] = self::entities('a');
        $o = new OtherEntity()->setOtherId('o');

        static::assertSame([$a, $o], OutputFactory::get([$a, $o])->toArray());
        static::assertSame([NormalEntity::class => [$a], OtherEntity::class => [$o]], OutputFactory::get([$a, $o])->grouped());
        static::assertNull(OutputFactory::get([])->first());
    }

    public function testAGetStandInStreamsOnceLikeARealOne(): void
    {
        [$a] = self::entities('a');
        $output = OutputFactory::get([$a]);

        static::assertSame([$a], $output->forEntity(NormalEntity::class));

        $this->expectException(\LogicException::class);
        $output->first();
    }

    /**
     * @return list<NormalEntity>
     */
    private static function entities(string ...$ids): array
    {
        return array_values(array_map(static fn (string $id): NormalEntity => new NormalEntity()->setAutofilledId($id)->setRequired('req'), $ids));
    }

    /**
     * @return array<string, string>
     */
    private static function keyOf(NormalEntity $entity): array
    {
        return ['autofilledId' => $entity->getAutofilledId()];
    }
}
