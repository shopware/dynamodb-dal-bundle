<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Client\Input;

use Shopware\DynamodbDalBundle\Client\Input\RefreshInput;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\NormalEntity;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\OtherEntity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RefreshInput::class)]
class RefreshInputTest extends TestCase
{
    public function testConsistentReadIsNullByDefault(): void
    {
        static::assertNull(new RefreshInput([])->consistentRead);
    }

    public function testWithEntityAppendsAcrossEntityClasses(): void
    {
        $a = new NormalEntity();
        $b = new OtherEntity();
        $c = new NormalEntity();

        $input = new RefreshInput([$a])->withEntity($b, $c);

        static::assertSame([$a, $b, $c], $input->entities);
    }

    public function testWithEntityReturnsANewInstanceAndKeepsTheConsistentReadFlag(): void
    {
        $a = new NormalEntity();
        $input = new RefreshInput([], consistentRead: true);

        $widened = $input->withEntity($a);

        static::assertNotSame($input, $widened);
        static::assertSame([], $input->entities);
        static::assertSame([$a], $widened->entities);
        static::assertTrue($widened->consistentRead);
    }

    public function testWithConsistentReadSetsTheFlagOnANewInstance(): void
    {
        $a = new NormalEntity();
        $input = new RefreshInput([$a]);

        $consistent = $input->withConsistentRead(true);

        static::assertNotSame($input, $consistent);
        static::assertNull($input->consistentRead);
        static::assertTrue($consistent->consistentRead);
        static::assertSame([$a], $consistent->entities);
    }
}
