<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Client;

use Shopware\DynamodbDalBundle\Client\Key;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\NormalEntity;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\OtherEntity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Key::class)]
class KeyTest extends TestCase
{
    public function testMapsItsValuesOntoTheTableKeyFields(): void
    {
        static::assertSame(['otherId' => 'o'], new Key(NormalEntity::class, 'o')->getFields(OtherEntity::createDefinition()));
    }

    public function testASortValueIsLeftOutOfATableWithoutASortKey(): void
    {
        static::assertSame(['autofilledId' => 'a'], new Key(NormalEntity::class, 'a', 'ignored')->getFields(NormalEntity::createDefinition()));
    }
}
