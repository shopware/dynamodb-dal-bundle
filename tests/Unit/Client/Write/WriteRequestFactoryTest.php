<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Client\Write;

use Shopware\DynamodbDalBundle\Client\Input\BatchWriteInput;
use Shopware\DynamodbDalBundle\Client\Input\DeleteInput;
use Shopware\DynamodbDalBundle\Client\Input\PutInput;
use Shopware\DynamodbDalBundle\Client\Input\Refresh;
use Shopware\DynamodbDalBundle\Client\Input\TransactWriteInput;
use Shopware\DynamodbDalBundle\Client\Input\UpdateInput;
use Shopware\DynamodbDalBundle\Client\Key;
use Shopware\DynamodbDalBundle\Client\Write\PreparedWrite;
use Shopware\DynamodbDalBundle\Client\Write\WriteBack;
use Shopware\DynamodbDalBundle\Client\Write\WriteRequestFactory;
use Shopware\DynamodbDalBundle\Definition\EntityDefinitionRegistry;
use Shopware\DynamodbDalBundle\Exception\DuplicateKeyException;
use Shopware\DynamodbDalBundle\Expression\Filter;
use Shopware\DynamodbDalBundle\Expression\FilterCompiler;
use Shopware\DynamodbDalBundle\Expression\Update;
use Shopware\DynamodbDalBundle\Expression\UpdateCompiler;
use Shopware\DynamodbDalBundle\Serializer\NormalizerOperation;
use Shopware\DynamodbDalBundle\Serializer\Serializer;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\NormalEntity;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WriteRequestFactory::class)]
#[CoversClass(PreparedWrite::class)]
#[CoversClass(WriteBack::class)]
class WriteRequestFactoryTest extends TestCase
{
    private WriteRequestFactory $factory;

    protected function setUp(): void
    {
        $serializer = new Serializer();
        $definition = NormalEntity::createDefinition();

        $this->factory = new WriteRequestFactory(
            $serializer,
            new FilterCompiler(),
            new UpdateCompiler($serializer),
            new EntityDefinitionRegistry([$definition->getName() => $definition]),
        );
    }

    /**
     * The normalizer fills the key in, and the entity takes back what it filled.
     */
    public function testAPutTakesBackTheFieldsItWroteAsAPut(): void
    {
        $entity = new NormalEntity()->setRequired('req');

        $writeBack = $this->factory->put(new PutInput($entity))->writeBack;

        static::assertSame($entity, $writeBack?->entity);
        static::assertSame(NormalizerOperation::Put, $writeBack->operation);
        static::assertSame('test-id', $writeBack->fields['autofilledId'] ?? null);
    }

    public function testAPutCarriesItsCondition(): void
    {
        $request = $this->factory->put(new PutInput($this->entity('a'), Filter::notExists('autofilledId')))->request;

        static::assertSame('NOT attribute_exists(#autofilledId)', $request['ConditionExpression'] ?? null);
    }

    /**
     * `UpdateItem` otherwise creates a missing item from just its key and the updated fields.
     */
    public function testAnUpdateIsConditionedOnItsItemExisting(): void
    {
        $request = $this->factory->update(new UpdateInput(new Key(NormalEntity::class, 'a'), ['name' => 'one']))->request;

        static::assertSame('attribute_exists(#autofilledId)', $request['ConditionExpression'] ?? null);
    }

    public function testAnUpdateOfWholeFieldsTakesBackTheFieldsItWroteAsAnUpdate(): void
    {
        $entity = $this->entity('a');

        $writeBack = $this->factory->update(new UpdateInput($entity, ['name' => 'one']))->writeBack;

        static::assertSame($entity, $writeBack?->entity);
        static::assertSame(['name' => 'one'], $writeBack->fields);
        static::assertSame(NormalizerOperation::Update, $writeBack->operation);
    }

    /**
     * DynamoDB computes an action's value from the stored item, so only a read can say what the update wrote.
     */
    public function testAnUpdateWithAnActionIsReadBack(): void
    {
        $writeBack = $this->factory->update(new UpdateInput($this->entity('a'), Update::setIfNotExists('name', 'first')))->writeBack;

        static::assertNotNull($writeBack);
        static::assertNull($writeBack->fields);
    }

    public function testAnUpdateWithoutReadBackTakesBackTheFieldsBesideAnAction(): void
    {
        $update = Update::with(Update::set('name', 'one'), Update::setIfNotExists('requiredNullableName', 'after'));

        $writeBack = $this->factory->update(new UpdateInput($this->entity('a'), $update, refresh: Refresh::WithoutReadBack))->writeBack;

        static::assertSame(['name' => 'one'], $writeBack?->fields);
    }

    public function testAnUpdateTakesNothingBackWithoutAnEntityOrARefresh(): void
    {
        static::assertNull($this->factory->update(new UpdateInput(new Key(NormalEntity::class, 'a'), ['name' => 'one']))->writeBack);
        static::assertNull($this->factory->update(new UpdateInput($this->entity('a'), ['name' => 'one'], refresh: Refresh::None))->writeBack);
    }

    public function testADeleteTakesNothingBack(): void
    {
        static::assertNull($this->factory->delete(new DeleteInput($this->entity('a')))->writeBack);
    }

    /**
     * A transaction takes each write as the member of its kind, with the request a lone write sends.
     */
    public function testATransactionTakesEachWriteAsTheMemberOfItsKind(): void
    {
        $put = $this->factory->prepare(new PutInput($this->entity('a')))->toTransactItem();
        $update = $this->factory->prepare(new UpdateInput($this->entity('a'), ['name' => 'one']))->toTransactItem();
        $delete = $this->factory->prepare(new DeleteInput($this->entity('a')))->toTransactItem();

        static::assertSame('normal', $put->getPut()?->getTableName());
        static::assertSame('SET #name = :u_1_0_name', $update->getUpdate()?->getUpdateExpression());
        static::assertSame('normal', $delete->getDelete()?->getTableName());
    }

    public function testABatchRefusesAKeyNamedTwice(): void
    {
        $this->expectException(DuplicateKeyException::class);

        $this->factory->batch(new BatchWriteInput([$this->entity('a')], [new Key(NormalEntity::class, 'a')]));
    }

    /**
     * A put names the key of the item it writes, an update or delete the key it is given.
     */
    public function testEachWriteKnowsTheKeyOfItsItem(): void
    {
        $key = ['autofilledId' => new AttributeValue(['S' => 'a'])];

        static::assertEquals($key, $this->factory->prepare(new PutInput($this->entity('a')))->key->fields);
        static::assertEquals($key, $this->factory->prepare(new UpdateInput(new Key(NormalEntity::class, 'a'), ['name' => 'one']))->key->fields);
        static::assertEquals($key, $this->factory->prepare(new DeleteInput($this->entity('a')))->key->fields);
    }

    public function testATransactionKeepsTheOrderOfItsOperations(): void
    {
        $writes = $this->factory->transaction(new TransactWriteInput(
            new DeleteInput(new Key(NormalEntity::class, 'b')),
            new PutInput($this->entity('a')),
        ));

        static::assertSame(['Delete', 'Put'], array_map(static fn (PreparedWrite $write): string => $write->type, $writes));
    }

    /**
     * DynamoDB refuses a transaction that writes an item twice, whichever operations do.
     */
    public function testATransactionRefusesAKeyNamedTwice(): void
    {
        $key = new Key(NormalEntity::class, 'a');

        try {
            $this->factory->transaction(new TransactWriteInput(new DeleteInput($key), new UpdateInput($key, ['name' => 'one'])));
            static::fail('The key is named twice');
        } catch (DuplicateKeyException $exception) {
            static::assertSame($key, $exception->key);
        }
    }

    private function entity(string $id): NormalEntity
    {
        return new NormalEntity()->setAutofilledId($id)->setRequired('req');
    }
}
