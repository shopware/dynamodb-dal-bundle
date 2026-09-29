<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Client\Write;

use Shopware\DynamodbDalBundle\Client\Input\BatchWriteInput;
use Shopware\DynamodbDalBundle\Client\Input\DeleteInput;
use Shopware\DynamodbDalBundle\Client\Input\PutInput;
use Shopware\DynamodbDalBundle\Client\Input\Refresh;
use Shopware\DynamodbDalBundle\Client\Input\TransactWriteInput;
use Shopware\DynamodbDalBundle\Client\Input\UpdateInput;
use Shopware\DynamodbDalBundle\Client\Input\UpsertInput;
use Shopware\DynamodbDalBundle\Client\Key;
use Shopware\DynamodbDalBundle\Client\Write\PreparedWrite;
use Shopware\DynamodbDalBundle\Client\Write\WriteBack;
use Shopware\DynamodbDalBundle\Client\Write\WriteRequestFactory;
use Shopware\DynamodbDalBundle\Definition\AttributeType;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\EntityDefinitionRegistry;
use Shopware\DynamodbDalBundle\Exception\AttributeTypeMismatchException;
use Shopware\DynamodbDalBundle\Exception\ConditionEmptyException;
use Shopware\DynamodbDalBundle\Exception\DuplicateKeyException;
use Shopware\DynamodbDalBundle\Exception\FieldMissingSerializedValueException;
use Shopware\DynamodbDalBundle\Exception\UpsertKeyMismatchException;
use Shopware\DynamodbDalBundle\Expression\Filter;
use Shopware\DynamodbDalBundle\Expression\FilterCompiler;
use Shopware\DynamodbDalBundle\Expression\Update;
use Shopware\DynamodbDalBundle\Expression\UpdateCompiler;
use Shopware\DynamodbDalBundle\Serializer\NormalizerContext;
use Shopware\DynamodbDalBundle\Serializer\NormalizerOperation;
use Shopware\DynamodbDalBundle\Serializer\Field\MapFieldSerializer;
use Shopware\DynamodbDalBundle\Serializer\Serializer;
use Shopware\DynamodbDalBundle\Test\EntityDefinitionFactory;
use Shopware\DynamodbDalBundle\Tests\Unit\Client\Write\Fixtures\Settings;
use Shopware\DynamodbDalBundle\Tests\Unit\Client\Write\Fixtures\SettingsEntity;
use Shopware\DynamodbDalBundle\Tests\Unit\Client\Write\Fixtures\SettingsFieldSerializer;
use Shopware\DynamodbDalBundle\Tests\Unit\Client\Write\Fixtures\SettingsNormalizer;
use Shopware\DynamodbDalBundle\Tests\Unit\Fixtures\CatalogEntity;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\NormalEntity;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\RecordingNormalizer;
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

    /**
     * An update never creates an item, and the put never replaces one, so at most one of the two is written.
     */
    public function testAnUpsertUpdatesAStoredItemAndPutsTheEntityWhereNoneIsStored(): void
    {
        [$update, $put] = $this->factory->upsert(new UpsertInput($this->entity('a')->setName('one'), ['name']));

        static::assertSame('Update', $update->type);
        static::assertSame('SET #name = :u_1_0_name', $update->request['UpdateExpression'] ?? null);
        static::assertSame('attribute_exists(#autofilledId)', $update->request['ConditionExpression'] ?? null);
        static::assertSame('Put', $put->type);
        static::assertSame('NOT attribute_exists(#autofilledId)', $put->request['ConditionExpression'] ?? null);
    }

    public function testBothWritesOfAnUpsertCarryItsCondition(): void
    {
        [$update, $put] = $this->factory->upsert(new UpsertInput($this->entity('a'), ['name'], Filter::notExists('name')));

        static::assertSame('attribute_exists(#autofilledId) AND NOT attribute_exists(#name)', $update->request['ConditionExpression'] ?? null);
        static::assertSame('NOT attribute_exists(#autofilledId) AND NOT attribute_exists(#name)', $put->request['ConditionExpression'] ?? null);
    }

    /**
     * The condition is compiled on its own for the put too, so it cannot hide behind the check that the key is free.
     */
    public function testAnUpsertConditionThatChecksNothingIsRefused(): void
    {
        $this->expectException(ConditionEmptyException::class);

        $this->factory->upsert(new UpsertInput($this->entity('a'), ['name'], Filter::or()));
    }

    /**
     * The update's key is normalized as a key, and the put's with the entity. A normalizer that gives the put another
     * key would have the two address different rows.
     */
    public function testAnUpsertWhoseNormalizerGivesThePutAnotherKeyIsRefused(): void
    {
        $normalizer = new RecordingNormalizer(normalize: static function (NormalizerContext $context): void {
            if ($context->operation === NormalizerOperation::Put) {
                $context->set('autofilledId', 'composed');
            }
        });
        $entity = $this->entity('a');

        try {
            self::factoryFor(NormalEntity::createDefinition($normalizer))->upsert(new UpsertInput($entity, ['name']));
            static::fail('The put is keyed by "composed", the update by "a"');
        } catch (UpsertKeyMismatchException $exception) {
            static::assertSame($entity, $exception->entity);
        }
    }

    /**
     * A path the entity holds no value for is removed, as a field given as `null` is. The key names the item, so a key
     * field in the paths writes nothing.
     */
    public function testAStoredItemTakesTheNamedPathsFromTheEntity(): void
    {
        [$update] = $this->factory->upsert(new UpsertInput($this->entity('a')->setName('one'), ['autofilledId', 'name', 'requiredNullableName']));

        static::assertSame('SET #name = :u_1_0_name REMOVE #requiredNullableName', $update->request['UpdateExpression'] ?? null);
    }

    public function testAStoredItemTakesAPathIntoAnAttributeFromTheEntitysValueThere(): void
    {
        $factory = self::factoryFor(EntityDefinitionFactory::create(CatalogEntity::class));

        [$update] = $factory->upsert(new UpsertInput(self::catalogEntity(['colors' => ['red', 'blue']]), ['groups.colors[1]']));

        static::assertSame('SET #groups.#colors[1] = :u_1_0_groups_2ecolors_5b1_5d', $update->request['UpdateExpression'] ?? null);
        static::assertEquals(new AttributeValue(['S' => 'blue']), $update->request['ExpressionAttributeValues'][':u_1_0_groups_2ecolors_5b1_5d'] ?? null);
    }

    /**
     * A map stores an entry that is `null` as `NULL`, which holds no value to write either.
     */
    public function testAnEntryTheEntityHoldsAsNullIsRemoved(): void
    {
        $factory = self::factoryFor(EntityDefinitionFactory::create(CatalogEntity::class));

        [$update] = $factory->upsert(new UpsertInput(self::catalogEntity(['colors' => null, 'sizes' => ['s']]), ['groups.colors']));

        static::assertSame('REMOVE #groups.#colors', $update->request['UpdateExpression'] ?? null);
    }

    /**
     * The entity holds an object, whose structure only its field serializer knows.
     */
    public function testAPathIntoAnObjectItsSerializerStoresAsAMapTakesTheValueThere(): void
    {
        $factory = self::factoryFor(SettingsEntity::createDefinition(new SettingsFieldSerializer()));

        [$update] = $factory->upsert(new UpsertInput(self::settingsEntity(), ['settings.theme']));

        static::assertSame('SET #settings.#theme = :u_1_0_settings_2etheme', $update->request['UpdateExpression'] ?? null);
        static::assertEquals(new AttributeValue(['S' => 'dark']), $update->request['ExpressionAttributeValues'][':u_1_0_settings_2etheme'] ?? null);
    }

    /**
     * The normalizer turns the object into the map a put stores, under a key the object itself has no property for.
     */
    public function testAPathIntoAnObjectItsNormalizerTurnsIntoAMapTakesTheValueThePutStoresThere(): void
    {
        $factory = self::factoryFor(SettingsEntity::createDefinition(new MapFieldSerializer(), new SettingsNormalizer()));

        [$update] = $factory->upsert(new UpsertInput(self::settingsEntity(), ['settings.colorScheme']));

        static::assertSame('SET #settings.#colorScheme = :u_1_0_settings_2ecolorScheme', $update->request['UpdateExpression'] ?? null);
        static::assertEquals(new AttributeValue(['S' => 'dark']), $update->request['ExpressionAttributeValues'][':u_1_0_settings_2ecolorScheme'] ?? null);
    }

    public function testAKeyTheStoredMapLacksIsRemoved(): void
    {
        $factory = self::factoryFor(SettingsEntity::createDefinition(new SettingsFieldSerializer()));

        [$update] = $factory->upsert(new UpsertInput(self::settingsEntity(), ['settings.contrast']));

        static::assertSame('REMOVE #settings.#contrast', $update->request['UpdateExpression'] ?? null);
    }

    /**
     * A field its serializer stores as a string, without declaring so, has no entries a path could reach. The upsert
     * is refused rather than removing the entry that was meant.
     */
    public function testAPathIntoAFieldTheItemStoresAsNoMapIsRefused(): void
    {
        $factory = self::factoryFor(SettingsEntity::createDefinition(new SettingsFieldSerializer(asString: true)));

        try {
            $factory->upsert(new UpsertInput(self::settingsEntity(), ['settings.theme']));
            static::fail('`settings` is stored as a string');
        } catch (AttributeTypeMismatchException $exception) {
            static::assertSame('settings', $exception->field);
            static::assertSame(AttributeType::String, $exception->actualType);
            static::assertSame([AttributeType::Map], $exception->expectedTypes);
        }
    }

    public function testAStoredItemTakesTheUpdateAsGiven(): void
    {
        [$update] = $this->factory->upsert(new UpsertInput($this->entity('a'), Update::setIfNotExists('name', 'first')));

        static::assertSame('SET #name = if_not_exists(#name, :u_1_0_name)', $update->request['UpdateExpression'] ?? null);
    }

    /**
     * The update brings the entity up to date as any update keyed by it, and the put as any put.
     */
    public function testAnUpsertBringsItsEntityUpToDateAsTheWriteThatStoredIt(): void
    {
        $entity = $this->entity('a');

        [$update, $put] = $this->factory->upsert(new UpsertInput($entity, ['name']));

        static::assertSame($entity, $update->writeBack?->entity);
        static::assertSame(NormalizerOperation::Update, $update->writeBack->operation);
        static::assertSame($entity, $put->writeBack?->entity);
        static::assertSame(NormalizerOperation::Put, $put->writeBack->operation);
    }

    public function testAnUpsertOptedOutOfTheRefreshLeavesItsEntityAsItIs(): void
    {
        [$update, $put] = $this->factory->upsert(new UpsertInput($this->entity('a'), ['name'], refresh: Refresh::None));

        static::assertNull($update->writeBack);
        static::assertNull($put->writeBack);
    }

    /**
     * Both are prepared before either is sent, so an entity that cannot be put fails without any write.
     */
    public function testAnUpsertOfAnEntityThatCannotBePutIsRefusedBeforeAnythingIsSent(): void
    {
        $this->expectException(FieldMissingSerializedValueException::class);

        $this->factory->upsert(new UpsertInput(new NormalEntity()->setAutofilledId('a'), ['name']));
    }

    private function entity(string $id): NormalEntity
    {
        return new NormalEntity()->setAutofilledId($id)->setRequired('req');
    }

    /**
     * @param array<string, ?list<string>> $groups
     */
    private static function catalogEntity(array $groups): CatalogEntity
    {
        return new CatalogEntity()->setVars([
            'tenantId' => 't',
            'status' => 'open',
            'createdAt' => new \DateTimeImmutable('@1700000000'),
            'groups' => $groups,
        ]);
    }

    private static function settingsEntity(): SettingsEntity
    {
        return new SettingsEntity()->setVars(['id' => 'a', 'settings' => new Settings('dark', 'de')]);
    }

    private static function factoryFor(EntityDefinition $definition): WriteRequestFactory
    {
        $serializer = new Serializer();

        return new WriteRequestFactory(
            $serializer,
            new FilterCompiler(),
            new UpdateCompiler($serializer),
            new EntityDefinitionRegistry([$definition->getName() => $definition]),
        );
    }
}
