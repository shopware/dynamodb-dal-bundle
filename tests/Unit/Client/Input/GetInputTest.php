<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Client\Input;

use Shopware\DynamodbDalBundle\Client\Input\GetInput;
use Shopware\DynamodbDalBundle\Client\Key;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\NormalEntity;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\OtherEntity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GetInput::class)]
class GetInputTest extends TestCase
{
    public function testKeepsKeysOfSeveralEntityClassesInTheirOrder(): void
    {
        $a = new Key(NormalEntity::class, 'a');
        $o = new Key(OtherEntity::class, 'o');
        $b = new Key(NormalEntity::class, 'b');

        static::assertSame([$a, $o, $b], new GetInput([$a, $o, $b])->keys);
    }

    public function testCompositeKeysArePreserved(): void
    {
        $key = new GetInput([new Key(NormalEntity::class, 'hash', 'range')])->keys[0];

        static::assertSame(NormalEntity::class, $key->class);
        static::assertSame('hash', $key->hashValue);
        static::assertSame('range', $key->rangeValue);
    }

    public function testAnInputWithoutKeysHasNone(): void
    {
        static::assertSame([], new GetInput()->keys);
    }

    public function testConsistentReadIsOffByDefault(): void
    {
        static::assertFalse(new GetInput()->consistentRead);
    }

    public function testWithKeyAppendsKeysToANewInstanceAndLeavesTheOriginalUntouched(): void
    {
        $a = new Key(NormalEntity::class, 'a');
        $o = new Key(OtherEntity::class, 'o');
        $input = new GetInput([$a]);

        $wider = $input->withKey($o, $a);

        static::assertSame([$a], $input->keys);
        static::assertSame([$a, $o, $a], $wider->keys);
    }

    public function testWithConsistentReadSetsTheFlagOnANewInstance(): void
    {
        $a = new Key(NormalEntity::class, 'a');
        $input = new GetInput([$a]);

        $consistent = $input->withConsistentRead();

        static::assertFalse($input->consistentRead);
        static::assertTrue($consistent->consistentRead);
        static::assertSame([$a], $consistent->keys);
        static::assertFalse($consistent->withConsistentRead(false)->consistentRead);
    }

    public function testWithKeyKeepsTheConsistentReadFlag(): void
    {
        static::assertTrue(new GetInput([], consistentRead: true)->withKey(new Key(NormalEntity::class, 'a'))->consistentRead);
    }
}
