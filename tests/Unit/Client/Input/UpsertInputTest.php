<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Client\Input;

use Shopware\DynamodbDalBundle\Client\Input\Refresh;
use Shopware\DynamodbDalBundle\Client\Input\UpsertInput;
use Shopware\DynamodbDalBundle\Expression\Filter;
use Shopware\DynamodbDalBundle\Expression\Update;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\NormalEntity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UpsertInput::class)]
class UpsertInputTest extends TestCase
{
    public function testAnUpsertNamesTheClassOfItsEntity(): void
    {
        $entity = new NormalEntity();
        $condition = Filter::notExists('name');

        $input = new UpsertInput($entity, ['name'], $condition, Refresh::None);

        static::assertSame(NormalEntity::class, $input->class);
        static::assertSame($entity, $input->entity);
        static::assertSame($condition, $input->condition);
        static::assertSame(Refresh::None, $input->refresh);
    }

    public function testAnUpsertBringsItsEntityUpToDateByDefault(): void
    {
        static::assertSame(Refresh::Full, new UpsertInput(new NormalEntity(), ['name'])->refresh);
    }

    public function testAnUpsertTakesEachPathOnce(): void
    {
        static::assertSame(['name', 'required'], new UpsertInput(new NormalEntity(), ['name', 'required', 'name'])->update);
    }

    public function testAnUpsertKeepsTheUpdateItIsGiven(): void
    {
        $update = Update::set('name', 'a');

        static::assertSame($update, new UpsertInput(new NormalEntity(), $update)->update);
    }
}
