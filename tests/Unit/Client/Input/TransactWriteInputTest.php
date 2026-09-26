<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Client\Input;

use Shopware\DynamodbDalBundle\Client\Input\DeleteInput;
use Shopware\DynamodbDalBundle\Client\Input\PutInput;
use Shopware\DynamodbDalBundle\Client\Input\TransactWriteInput;
use Shopware\DynamodbDalBundle\Client\Input\UpdateInput;
use Shopware\DynamodbDalBundle\Client\Key;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\NormalEntity;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\OtherEntity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TransactWriteInput::class)]
class TransactWriteInputTest extends TestCase
{
    public function testAnEmptyTransactionHasNoOperations(): void
    {
        static::assertSame([], new TransactWriteInput()->operations);
    }

    /**
     * A failed transaction reports its cancellation reasons by position, so the operations stay in the order given.
     */
    public function testKeepsTheOperationsInTheOrderGivenAcrossEntityClasses(): void
    {
        $put = new PutInput(new NormalEntity()->setAutofilledId('a')->setRequired('req'));
        $delete = new DeleteInput(new Key(OtherEntity::class, 'o'));
        $update = new UpdateInput(new Key(NormalEntity::class, 'b'), ['name' => 'two']);

        static::assertSame([$put, $delete, $update], new TransactWriteInput($put, $delete, $update)->operations);
        static::assertSame([$put, $delete, $update], new TransactWriteInput($put)->with($delete)->with($update)->operations);
    }

    public function testEachOperationNamesItsEntityClass(): void
    {
        $operations = new TransactWriteInput(
            new PutInput(new NormalEntity()->setAutofilledId('a')->setRequired('req')),
            new DeleteInput(new Key(OtherEntity::class, 'o')),
            new UpdateInput(new OtherEntity()->setOtherId('p'), ['otherId' => 'p']),
        )->operations;

        static::assertSame(
            [NormalEntity::class, OtherEntity::class, OtherEntity::class],
            array_map(static fn (PutInput|UpdateInput|DeleteInput $operation): string => $operation->class, $operations),
        );
    }

    public function testAddingLeavesTheTransactionItWasAddedToAsItIs(): void
    {
        $transaction = new TransactWriteInput(new DeleteInput(new Key(NormalEntity::class, 'a')));

        $transaction->with(new DeleteInput(new Key(NormalEntity::class, 'b')));

        static::assertCount(1, $transaction->operations);
    }
}
