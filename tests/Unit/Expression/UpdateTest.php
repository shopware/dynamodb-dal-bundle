<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Expression;

use Shopware\DynamodbDalBundle\Expression\Update;
use Shopware\DynamodbDalBundle\Expression\Update\AddAction;
use Shopware\DynamodbDalBundle\Expression\Update\DeleteAction;
use Shopware\DynamodbDalBundle\Expression\Update\ListAppendAction;
use Shopware\DynamodbDalBundle\Expression\Update\SetIfNotExistsAction;
use Shopware\DynamodbDalBundle\Expression\Update\UpdateExpression;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Update::class)]
class UpdateTest extends TestCase
{
    public function testFieldsSetAndRemoveAreFields(): void
    {
        static::assertEquals(new UpdateExpression(['a' => 1, 'b' => null]), Update::setFields(['a' => 1, 'b' => null]));
        static::assertEquals(new UpdateExpression(['a' => 1]), Update::set('a', 1));
        static::assertEquals(new UpdateExpression(['a' => null]), Update::set('a', null));
        static::assertEquals(new UpdateExpression(['a' => null]), Update::remove('a'));
    }

    public function testComputedValuesAreActions(): void
    {
        static::assertEquals(new UpdateExpression([], [new SetIfNotExistsAction('a', 1)]), Update::setIfNotExists('a', 1));
        static::assertEquals(new UpdateExpression([], [new AddAction('a', 2)]), Update::increment('a', 2));
        static::assertEquals(new UpdateExpression([], [new AddAction('a', -2)]), Update::decrement('a', 2));
        static::assertEquals(new UpdateExpression([], [new ListAppendAction('a', [1])]), Update::append('a', [1]));
        static::assertEquals(new UpdateExpression([], [new ListAppendAction('a', [1], prepend: true)]), Update::prepend('a', [1]));
        static::assertEquals(new UpdateExpression([], [new AddAction('a', [1])]), Update::addToSet('a', [1]));
        static::assertEquals(new UpdateExpression([], [new DeleteAction('a', [1])]), Update::removeFromSet('a', [1]));
    }

    public function testWithStartsFromActionsOfYourOwn(): void
    {
        $first = new AddAction('a', 1);
        $second = new DeleteAction('b', [1]);

        static::assertSame([$first, $second], Update::with($first, $second)->actions);
    }

    public function testWithCombinesFieldsAndActions(): void
    {
        $update = Update::with(
            Update::set('status', 'paid'),
            Update::remove('note'),
            Update::increment('attempts'),
        );

        static::assertSame(['status' => 'paid', 'note' => null], $update->fields);
        static::assertEquals([new AddAction('attempts', 1)], $update->actions);
    }

    public function testWithTakesTheLastValueOfAPathAndKeepsTheOrderOfActions(): void
    {
        $own = new DeleteAction('b', [1]);

        $update = Update::with(
            Update::set('a', 1),
            $own,
            Update::with(Update::set('c', 1), Update::increment('d')),
            Update::setFields(['a' => 2, 'c' => null]),
        );

        static::assertSame(['a' => 2, 'c' => null], $update->fields);
        static::assertEquals([$own, new AddAction('d', 1)], $update->actions);
    }
}
