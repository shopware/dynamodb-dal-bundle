<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Client\Input;

use Shopware\DynamodbDalBundle\Client\Index;
use Shopware\DynamodbDalBundle\Client\Input\GetInput;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\NormalEntity;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\OtherEntity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GetInput::class)]
class GetInputTest extends TestCase
{
    public function testConstructorKeepsEntityClassesWithKeys(): void
    {
        $a = new Index('a');
        $b = new Index('b');

        $input = new GetInput([
            NormalEntity::class => [$a],
            OtherEntity::class => [$b],
        ]);

        static::assertSame([
            NormalEntity::class => [$a],
            OtherEntity::class => [$b],
        ], $input->keysByClass);
    }

    public function testConstructorDropsEntityClassesWithoutKeys(): void
    {
        $a = new Index('a');

        $input = new GetInput([
            NormalEntity::class => [$a],
            OtherEntity::class => [],
        ]);

        static::assertSame([NormalEntity::class => [$a]], $input->keysByClass);
    }

    public function testCompositeKeysArePreserved(): void
    {
        $input = new GetInput([NormalEntity::class => [new Index('hash', 'range')]]);

        static::assertSame('hash', $input->keysByClass[NormalEntity::class][0]->hashValue);
        static::assertSame('range', $input->keysByClass[NormalEntity::class][0]->rangeValue);
    }

    public function testConsistentReadIsNullByDefault(): void
    {
        static::assertNull(new GetInput([])->consistentRead);
    }

    public function testWithKeyCreatesABucketForTheEntityClass(): void
    {
        $a = new Index('a');

        $input = new GetInput([])->withKey(NormalEntity::class, $a);

        static::assertSame([NormalEntity::class => [$a]], $input->keysByClass);
    }

    public function testWithKeyAppendsToExistingBucket(): void
    {
        $a = new Index('a');
        $b = new Index('b');

        $input = new GetInput([NormalEntity::class => [$a]])->withKey(NormalEntity::class, $b);

        static::assertSame([NormalEntity::class => [$a, $b]], $input->keysByClass);
    }

    public function testWithKeyAppendsSeveralKeys(): void
    {
        $a = new Index('a');
        $b = new Index('b');

        $input = new GetInput([])->withKey(NormalEntity::class, $a, $b);

        static::assertSame([NormalEntity::class => [$a, $b]], $input->keysByClass);
    }

    public function testWithKeyWithoutKeysDoesNotCreateABucket(): void
    {
        $input = new GetInput([])->withKey(NormalEntity::class);

        static::assertSame([], $input->keysByClass);
        static::assertArrayNotHasKey(NormalEntity::class, $input->keysByClass);
    }

    public function testConstructorDropsEveryEmptyEntityClass(): void
    {
        $input = new GetInput([NormalEntity::class => [], OtherEntity::class => []]);

        static::assertSame([], $input->keysByClass);
    }

    public function testWithKeyPreservesEntityClassesAddedAcrossSeveralCalls(): void
    {
        $a = new Index('a');
        $b = new Index('b');

        $input = new GetInput([])
            ->withKey(NormalEntity::class, $a)
            ->withKey(OtherEntity::class, $b);

        static::assertSame([
            NormalEntity::class => [$a],
            OtherEntity::class => [$b],
        ], $input->keysByClass);
    }

    public function testWithKeyReturnsANewInstanceAndLeavesTheOriginalUntouched(): void
    {
        $a = new Index('a');
        $b = new Index('b');

        $input = new GetInput([NormalEntity::class => [$a]]);
        $widened = $input->withKey(OtherEntity::class, $b);

        static::assertNotSame($input, $widened);
        static::assertSame([NormalEntity::class => [$a]], $input->keysByClass);
        static::assertSame([
            NormalEntity::class => [$a],
            OtherEntity::class => [$b],
        ], $widened->keysByClass);
    }

    public function testWithConsistentReadSetsTheFlagOnANewInstance(): void
    {
        $a = new Index('a');
        $input = new GetInput([NormalEntity::class => [$a]]);

        $consistent = $input->withConsistentRead(true);

        static::assertNotSame($input, $consistent);
        static::assertNull($input->consistentRead);
        static::assertTrue($consistent->consistentRead);
        // The keys survive the copy.
        static::assertSame([NormalEntity::class => [$a]], $consistent->keysByClass);

        static::assertNull($consistent->withConsistentRead(null)->consistentRead);
    }

    public function testWithKeyKeepsTheConsistentReadFlag(): void
    {
        $input = new GetInput([])
            ->withConsistentRead(true)
            ->withKey(NormalEntity::class, new Index('a'));

        static::assertTrue($input->consistentRead);
    }
}
