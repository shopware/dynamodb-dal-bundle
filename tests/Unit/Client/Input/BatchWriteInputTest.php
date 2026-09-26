<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Client\Input;

use Shopware\DynamodbDalBundle\Client\Input\BatchWriteInput;
use Shopware\DynamodbDalBundle\Client\Key;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\NormalEntity;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\OtherEntity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BatchWriteInput::class)]
class BatchWriteInputTest extends TestCase
{
    public function testAnEmptyBatchHasNoPutsAndNoDeletes(): void
    {
        $batch = new BatchWriteInput();

        static::assertSame([], $batch->puts);
        static::assertSame([], $batch->deletes);
    }

    public function testTakesPutsAndDeletesOfSeveralEntityClasses(): void
    {
        $a = new NormalEntity()->setAutofilledId('a')->setRequired('req');
        $key = new Key(OtherEntity::class, 'o');
        $b = new NormalEntity()->setAutofilledId('b')->setRequired('req');

        $batch = new BatchWriteInput(puts: [$a], deletes: [$key, $b]);

        static::assertSame([$a], $batch->puts);
        static::assertSame([$key, $b], $batch->deletes);
    }

    public function testWithPutAndWithDeleteAddToANewBatch(): void
    {
        $a = new NormalEntity()->setAutofilledId('a')->setRequired('req');
        $key = new Key(OtherEntity::class, 'o');

        $batch = new BatchWriteInput()->withPut($a)->withDelete($key);

        static::assertSame([$a], $batch->puts);
        static::assertSame([$key], $batch->deletes);
    }

    public function testAddingLeavesTheBatchItWasAddedToAsItIs(): void
    {
        $batch = new BatchWriteInput()->withDelete(new Key(NormalEntity::class, 'a'));

        $batch->withDelete(new Key(NormalEntity::class, 'b'));
        $batch->withPut(new NormalEntity()->setAutofilledId('c')->setRequired('req'));

        static::assertCount(1, $batch->deletes);
        static::assertSame([], $batch->puts);
    }
}
