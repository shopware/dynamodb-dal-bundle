<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Client;

use Shopware\DynamodbDalBundle\Client\Output\GetOutput;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\NormalEntity;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\OtherEntity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GetOutput::class)]
class GetOutputTest extends TestCase
{
    public function testStreamsEveryEntityFromTheSourceGenerator(): void
    {
        $a = new NormalEntity()->setAutofilledId('a')->setRequired('req');
        $b = new NormalEntity()->setAutofilledId('b')->setRequired('req');

        $result = $this->keyOutput((static function () use ($a, $b): \Generator {
            yield $a;
            yield $b;
        })());

        static::assertSame([$a, $b], $result->toArray());
        static::assertSame([$a, $b], iterator_to_array($result));
        static::assertSame($a, $result->first());
    }

    public function testStreamsEveryEntityFlatAcrossTables(): void
    {
        $a = new NormalEntity()->setAutofilledId('a')->setRequired('req');
        $o = new OtherEntity()->setOtherId('o');

        $result = $this->keyOutput((static function () use ($a, $o): \Generator {
            yield $a;
            yield $o;
        })());

        static::assertSame([$a, $o], $result->toArray());
        static::assertSame([$a, $o], iterator_to_array($result));
    }

    public function testBucketsEntitiesByTheirClass(): void
    {
        $a = new NormalEntity()->setAutofilledId('a')->setRequired('req');
        $b = new NormalEntity()->setAutofilledId('b')->setRequired('req');
        $o = new OtherEntity()->setOtherId('o');

        $result = $this->keyOutput((static function () use ($a, $b, $o): \Generator {
            yield $a;
            yield $o;
            yield $b;
        })());

        static::assertSame([$a, $b], $result->forEntity(NormalEntity::class));
        static::assertSame([$o], $result->forEntity(OtherEntity::class));
        static::assertSame([
            NormalEntity::class => [$a, $b],
            OtherEntity::class => [$o],
        ], $result->grouped());
        // The flat terminals still iterate every table.
        static::assertSame([$a, $o, $b], $result->toArray());
    }

    public function testForEntityWithoutAMatchIsEmpty(): void
    {
        $a = new NormalEntity()->setAutofilledId('a')->setRequired('req');

        $result = $this->keyOutput((static function () use ($a): \Generator {
            yield $a;
        })());

        static::assertSame([], $result->forEntity(OtherEntity::class));
    }

    public function testReadsTheSourceOnceAndSharesItAcrossTerminals(): void
    {
        $a = new NormalEntity()->setAutofilledId('a')->setRequired('req');
        $b = new OtherEntity()->setOtherId('b');

        // A generator yields only once; identical results across terminals prove the cache is shared
        // rather than the source being re-read (which would observe an exhausted generator).
        $result = $this->keyOutput((static function () use ($a, $b): \Generator {
            yield $a;
            yield $b;
        })());

        static::assertSame($a, $result->first());
        static::assertSame([$a], $result->forEntity(NormalEntity::class));
        static::assertSame([$a, $b], $result->toArray());
        static::assertSame([$a, $b], $result->toArray());
        static::assertSame([
            NormalEntity::class => [$a],
            OtherEntity::class => [$b],
        ], $result->grouped());
    }

    public function testEmptyGeneratorYieldsNothing(): void
    {
        $result = $this->keyOutput((static function (): \Generator {
            yield from [];
        })());

        static::assertSame([], $result->toArray());
        static::assertNull($result->first());
        static::assertSame([], $result->forEntity(NormalEntity::class));
        static::assertSame([], $result->grouped());
    }

    /**
     * @param \Generator<int, NormalEntity|OtherEntity> $source
     *
     * @return GetOutput<NormalEntity|OtherEntity>
     */
    private function keyOutput(\Generator $source): GetOutput
    {
        return new GetOutput($source);
    }
}
