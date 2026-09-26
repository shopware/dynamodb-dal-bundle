<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Client;

use Shopware\DynamodbDalBundle\Client\Output\GetOutput;
use Shopware\DynamodbDalBundle\Client\Output\ReadOutput;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\NormalEntity;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\OtherEntity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GetOutput::class)]
#[CoversClass(ReadOutput::class)]
class GetOutputTest extends TestCase
{
    public function testStreamsEveryEntityFromTheSource(): void
    {
        $a = new NormalEntity()->setAutofilledId('a')->setRequired('req');
        $b = new NormalEntity()->setAutofilledId('b')->setRequired('req');

        static::assertSame([$a, $b], self::getOutput($a, $b)->toArray());
        static::assertSame([$a, $b], iterator_to_array(self::getOutput($a, $b)));
        static::assertSame($a, self::getOutput($a, $b)->first());
    }

    public function testStreamsEveryEntityFlatAcrossTables(): void
    {
        $a = new NormalEntity()->setAutofilledId('a')->setRequired('req');
        $o = new OtherEntity()->setOtherId('o');

        static::assertSame([$a, $o], self::getOutput($a, $o)->toArray());
    }

    public function testBucketsEntitiesByTheirClass(): void
    {
        $a = new NormalEntity()->setAutofilledId('a')->setRequired('req');
        $b = new NormalEntity()->setAutofilledId('b')->setRequired('req');
        $o = new OtherEntity()->setOtherId('o');

        static::assertSame([$a, $b], self::getOutput($a, $o, $b)->forEntity(NormalEntity::class));
        static::assertSame([$o], self::getOutput($a, $o, $b)->forEntity(OtherEntity::class));
        static::assertSame([
            NormalEntity::class => [$a, $b],
            OtherEntity::class => [$o],
        ], self::getOutput($a, $o, $b)->grouped());
    }

    public function testForEntityWithoutAMatchIsEmpty(): void
    {
        static::assertSame([], self::getOutput(new NormalEntity()->setAutofilledId('a')->setRequired('req'))->forEntity(OtherEntity::class));
    }

    public function testReadsNothingUntilTheOutputIsRead(): void
    {
        /** @var \ArrayObject<int, string> $pulled */
        $pulled = new \ArrayObject();

        $output = new GetOutput((static function () use ($pulled): \Generator {
            $pulled->append('read');

            yield new NormalEntity()->setAutofilledId('a')->setRequired('req');
        })());

        static::assertCount(0, $pulled);

        $output->first();

        static::assertCount(1, $pulled);
    }

    /**
     * Keys are read as the stream reaches them, so there is nothing kept to read a second time.
     */
    public function testThrowsWhenReadASecondTime(): void
    {
        $output = self::getOutput(new NormalEntity()->setAutofilledId('a')->setRequired('req'));

        $output->grouped();

        $this->expectException(\LogicException::class);
        $output->forEntity(NormalEntity::class);
    }

    public function testAnEmptySourceYieldsNothing(): void
    {
        static::assertSame([], self::getOutput()->toArray());
        static::assertNull(self::getOutput()->first());
        static::assertSame([], self::getOutput()->forEntity(NormalEntity::class));
        static::assertSame([], self::getOutput()->grouped());
    }

    /**
     * @return GetOutput<NormalEntity|OtherEntity>
     */
    private static function getOutput(NormalEntity|OtherEntity ...$entities): GetOutput
    {
        return new GetOutput((static function () use ($entities): \Generator {
            foreach ($entities as $entity) {
                yield $entity;
            }
        })());
    }
}
