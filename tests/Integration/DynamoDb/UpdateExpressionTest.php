<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Integration\DynamoDb;

use AsyncAws\Core\Exception\Http\ClientException;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;
use AsyncAws\DynamoDb\Exception\ConditionalCheckFailedException;
use AsyncAws\DynamoDb\Exception\TransactionCanceledException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Shopware\DynamodbDalBundle\Client\Key;
use Shopware\DynamodbDalBundle\Client\Input\GetInput;
use Shopware\DynamodbDalBundle\Client\Input\PutInput;
use Shopware\DynamodbDalBundle\Client\Input\Refresh;
use Shopware\DynamodbDalBundle\Client\Input\TransactWriteInput;
use Shopware\DynamodbDalBundle\Client\Input\UpdateInput;
use Shopware\DynamodbDalBundle\Exception\UpdateEmptyException;
use Shopware\DynamodbDalBundle\Expression\Contract\FilterInterface;
use Shopware\DynamodbDalBundle\Expression\Contract\UpdateActionInterface;
use Shopware\DynamodbDalBundle\Expression\ExpressionCompileContext;
use Shopware\DynamodbDalBundle\Expression\Filter;
use Shopware\DynamodbDalBundle\Definition\FieldPath;
use Shopware\DynamodbDalBundle\Expression\Update;
use Shopware\DynamodbDalBundle\Expression\Update\UpdateClause;
use Shopware\DynamodbDalBundle\Expression\Update\UpdateExpression;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\DynamoDbTestKernel;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\Entity\ArchiveEntity;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\Entity\NormalizedEntity;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\Entity\NormalizedEntityNormalizer;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\Entity\RecordEntity;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\Entity\RecordStatus;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\Entity\StringSet;

/**
 * What an {@see UpdateExpression} does with a {@see FieldPath} and with each action, asked of DynamoDB
 * rather than of an assertion about the string we generated.
 *
 * A recurring subject is how the placeholders avoid colliding. An update and its condition are compiled
 * separately and merged into one request, so both halves may spell the same path at the same moment,
 * and the two kinds of placeholder settle that differently:
 *
 * - a **name** answers "which attribute", which is a function of the attribute itself, so both halves
 *   derive the identical entry and merging restates it instead of one overwriting the other;
 * - a **value** answers "which value, here", which the two halves disagree about by design — one is
 *   what to write, the other what to require — so each is namespaced by the half that produced it.
 *
 * Getting either wrong fails quietly rather than loudly, which is why this is exercised end to end: a
 * collided value has the condition compare against the value being written, so it passes whatever the
 * stored row held and the write lands with the wrong value.
 */
#[CoversClass(FieldPath::class)]
#[CoversClass(UpdateExpression::class)]
class UpdateExpressionTest extends DynamoDbTestCase
{
    public function testUpdateWritesOneMapEntryAndLeavesItsSiblings(): void
    {
        $entity = RecordEntity::create(self::TENANT, 'a');
        $entity->meta = ['first' => 'one', 'second' => 'two'];
        $this->put($entity);

        $this->update('a', ['meta.first' => 'one-renewed']);

        $found = $this->read('a');
        static::assertSame('one-renewed', $found?->meta['first'] ?? null);
        // Never sent, so a write that landed between the read and this one keeps it.
        static::assertSame('two', $found?->meta['second'] ?? null);
    }

    public function testUpdateRemovesTheMapEntryGivenAsNull(): void
    {
        $entity = RecordEntity::create(self::TENANT, 'a');
        $entity->meta = ['first' => 'one', 'second' => 'two'];
        $this->put($entity);

        $this->update('a', ['meta.first' => null]);

        $found = $this->read('a');
        static::assertArrayNotHasKey('first', $found->meta ?? []);
        static::assertSame('two', $found->meta['second'] ?? null);
    }

    public function testUpdateWritesAMapKeyDynamoDbCannotSpellLiterally(): void
    {
        // A map key is caller data and need not be placeholder-safe.
        $entity = RecordEntity::create(self::TENANT, 'a');
        $entity->meta = ['sub-1' => 'old'];
        $this->put($entity);

        $this->update('a', ['meta.sub-2/a b' => 'new']);

        $found = $this->read('a');
        static::assertSame('new', $found?->meta['sub-2/a b'] ?? null);
        static::assertSame('old', $found?->meta['sub-1'] ?? null);
    }

    public function testUpdateWritesAWholeAttributeAndAnEntryOfAnotherTogether(): void
    {
        $entity = RecordEntity::create(self::TENANT, 'a');
        $entity->meta = ['first' => 'one'];
        $entity->groups = ['g' => ['old']];
        $this->put($entity);

        // A plain attribute and a nested path in one expression compile into the same update.
        $this->update('a', [
            'groups' => ['h' => ['new']],
            'meta.second' => 'two',
        ]);

        $found = $this->read('a');
        // Named whole, so it is replaced whole.
        static::assertSame(['h' => ['new']], $found?->groups);
        static::assertSame(['first' => 'one', 'second' => 'two'], $found->meta);
    }

    public function testUpdateOfAnEntryUnderAMissingAttributeIsRejected(): void
    {
        // A nested path cannot create what it descends through: with no `meta` map on the item there is
        // nothing to put the entry in, and DynamoDB refuses the whole update. A put always writes the map,
        // so it has to be removed raw.
        $this->put(RecordEntity::create(self::TENANT, 'a'));
        $this->dynamo()->updateItem([
            'TableName' => DynamoDbTestKernel::TABLES['record'],
            'Key' => [
                'tenantId' => new AttributeValue(['S' => self::TENANT]),
                'id' => new AttributeValue(['S' => 'a']),
            ],
            'UpdateExpression' => 'REMOVE meta',
        ])->resolve();

        static::expectException(ClientException::class);
        static::expectExceptionMessageMatches('/document path provided in the update expression is invalid/');

        $this->update('a', ['meta.first' => 'one']);
    }

    public function testUpdateOfAnEntryOnAMissingItemFailsTheExistenceCheckFirst(): void
    {
        // The item's existence is checked before the path is resolved, so a missing item fails the
        // condition, not the path.
        static::expectException(ConditionalCheckFailedException::class);

        $this->update('never-written', ['meta.first' => 'one']);
    }

    public function testUpdateOfAWholeAttributeDoesNotCreateTheMissingRow(): void
    {
        // `UpdateItem` alone would create a row from the key and what was written, which the DAL then
        // refuses to deserialize over the required fields it lacks.
        try {
            $this->update('absent-whole', ['meta' => ['first' => 'one']]);
            static::fail('An update of a missing item should fail its condition.');
        } catch (ConditionalCheckFailedException) {
            // Expected.
        }

        // Read raw, so a partial row could not hide behind a deserialization failure.
        $item = $this->dynamo()->getItem([
            'TableName' => DynamoDbTestKernel::TABLES['record'],
            'Key' => [
                'tenantId' => new AttributeValue(['S' => self::TENANT]),
                'id' => new AttributeValue(['S' => 'absent-whole']),
            ],
        ])->getItem();

        static::assertSame([], $item);
    }

    public function testUpdateMayRequireTheVeryEntryItWrites(): void
    {
        $entity = RecordEntity::create(self::TENANT, 'a');
        $entity->meta = ['first' => 'old', 'second' => 'two'];
        $this->put($entity);

        $this->update('a', ['meta.first' => 'new'], Filter::equals('meta.first', 'old'));

        $found = $this->read('a');
        static::assertSame('new', $found?->meta['first'] ?? null);
        static::assertSame('two', $found?->meta['second'] ?? null);
    }

    public function testUpdateIsRejectedWhenTheEntryItWritesNoLongerHoldsTheRequiredValue(): void
    {
        $entity = RecordEntity::create(self::TENANT, 'a');
        $entity->meta = ['first' => 'current'];
        $this->put($entity);

        try {
            // Whoever read `stale` has been overtaken, so this write is not theirs to make.
            $this->update('a', ['meta.first' => 'new'], Filter::equals('meta.first', 'stale'));

            static::fail('Expected the condition on the written entry to reject the update.');
        } catch (ConditionalCheckFailedException) {
            // The condition really was evaluated against the stored entry, not against what is being written.
        }

        static::assertSame('current', $this->read('a')?->meta['first'] ?? null);
    }

    public function testUpdateMayRequireOneEntryWhileWritingAnother(): void
    {
        $entity = RecordEntity::create(self::TENANT, 'a');
        $entity->meta = ['first' => 'one'];
        $this->put($entity);

        // Two different paths over one attribute: `#meta` is shared between them, the leaf placeholders
        // are not.
        $this->update('a', ['meta.second' => 'two'], Filter::equals('meta.first', 'one'));

        $found = $this->read('a');
        static::assertSame('two', $found?->meta['second'] ?? null);
        static::assertSame('one', $found?->meta['first'] ?? null);
    }

    public function testUpdateWritesOneElementOfAListInPlace(): void
    {
        $entity = RecordEntity::create(self::TENANT, 'a', tags: ['first', 'second']);
        $this->put($entity);

        $this->update('a', ['tags[1]' => 'replaced']);

        static::assertSame(['first', 'replaced'], $this->read('a')?->tags);
    }

    public function testUpdateDescendsThroughAMapIntoAListElement(): void
    {
        $entity = RecordEntity::create(self::TENANT, 'a');
        $entity->groups = ['g' => ['one', 'two']];
        $this->put($entity);

        $this->update('a', ['groups.g[0]' => 'replaced']);

        static::assertSame(['g' => ['replaced', 'two']], $this->read('a')?->groups);
    }

    /**
     * Why the entity is refreshed from the row rather than from what was sent: `meta.first` is a document
     * path, not a property, so there is nothing on the entity to assign it to. Only the row knows the
     * written entry and its untouched siblings at once.
     */
    public function testUpdateKeyedByTheEntityRefreshesTheWholeMapItWroteOneEntryOf(): void
    {
        $entity = RecordEntity::create(self::TENANT, 'a');
        $entity->meta = ['first' => 'one', 'second' => 'two'];
        $this->put($entity);

        $this->client()->update(new UpdateInput($entity, ['meta.first' => 'one-renewed']));

        static::assertSame(['first' => 'one-renewed', 'second' => 'two'], $entity->meta);
    }

    public function testUpdateKeyedByTheEntityRefreshesAListItReplacedOneElementOf(): void
    {
        $entity = RecordEntity::create(self::TENANT, 'a', tags: ['first', 'second']);
        $this->put($entity);

        $this->client()->update(new UpdateInput($entity, ['tags[1]' => 'replaced']));

        static::assertSame(['first', 'replaced'], $entity->tags);
    }

    /**
     * The atomic counter the SET/REMOVE-only update could not express: two writers adding at once both
     * count, because DynamoDB adds to what is stored rather than writing what either of them read.
     */
    public function testAddCountsOnFromTheStoredNumber(): void
    {
        $this->put(RecordEntity::create(self::TENANT, 'a', counter: 5));

        $this->update('a', Update::increment('counter', 2));
        $this->update('a', Update::increment('counter', -1));

        static::assertSame(6, $this->read('a')?->counter);
    }

    public function testAddStartsAMissingNumberAtZero(): void
    {
        $this->put(RecordEntity::create(self::TENANT, 'a', counter: 5));
        $this->removeRaw('a', 'counter');

        $this->update('a', Update::increment('counter', 3));

        static::assertSame(3, $this->read('a')?->counter);
    }

    public function testIncrementAndDecrementCountFromTheStoredNumber(): void
    {
        $this->put(RecordEntity::create(self::TENANT, 'a', counter: 5));

        $this->update('a', Update::with(Update::increment('counter', 3), Update::decrement('amount', 0.5)));

        $found = $this->read('a');
        static::assertSame(8, $found?->counter);
        static::assertSame(-0.5, $found->amount);
    }

    /**
     * `ADD` in an update expression takes a nested path too. Only the legacy `AttributeUpdates` parameter
     * was limited to top-level attributes.
     */
    public function testIncrementCountsAMapEntryAndStartsAMissingOneAtZero(): void
    {
        $entity = RecordEntity::create(self::TENANT, 'a');
        $entity->counts = ['seen' => 4];
        $this->put($entity);

        $this->update('a', Update::with(Update::increment('counts.seen'), Update::increment('counts.new', 2)));

        // A map has no order.
        static::assertEquals(['seen' => 5, 'new' => 2], $this->read('a')?->counts);
    }

    public function testAppendAndPrependExtendAList(): void
    {
        $this->put(RecordEntity::create(self::TENANT, 'a', tags: ['b']));

        $this->update('a', Update::append('tags', ['c', 'd']));
        $this->update('a', Update::prepend('tags', ['a']));

        static::assertSame(['a', 'b', 'c', 'd'], $this->read('a')?->tags);
    }

    public function testAppendStartsAMissingListUnderAMap(): void
    {
        $entity = RecordEntity::create(self::TENANT, 'a');
        $entity->groups = ['g' => ['one']];
        $this->put($entity);

        $this->update('a', Update::with(Update::append('groups.g', ['two']), Update::append('groups.h', ['first'])));

        // A map has no order.
        static::assertEquals(['g' => ['one', 'two'], 'h' => ['first']], $this->read('a')?->groups);
    }

    public function testSetIfNotExistsFillsAMissingValueAndKeepsAStoredOne(): void
    {
        $this->put(RecordEntity::create(self::TENANT, 'a'));

        $this->update('a', Update::setIfNotExists('name', 'first'));
        $this->update('a', Update::setIfNotExists('name', 'second'));

        static::assertSame('first', $this->read('a')?->name);
    }

    /**
     * Fields, actions and the condition are compiled apart and share one request. `counter` appears in the
     * condition and the update at once, which only works because the halves namespace their values apart.
     */
    public function testFieldsActionsAndAConditionGoOutAsOneUpdate(): void
    {
        $this->put(RecordEntity::create(self::TENANT, 'a', name: 'before', counter: 1, tags: ['x']));

        $this->update(
            'a',
            Update::with(
                Update::set('status', RecordStatus::Done),
                Update::remove('name'),
                Update::increment('counter', 1),
                Update::append('tags', ['y']),
            ),
            Filter::and(Filter::equals('status', RecordStatus::Open), Filter::equals('counter', 1)),
        );

        $found = $this->read('a');
        static::assertSame(RecordStatus::Done, $found?->status);
        static::assertNull($found->name);
        static::assertSame(2, $found->counter);
        static::assertSame(['x', 'y'], $found->tags);
    }

    public function testAnActionIsRefusedWhenTheConditionFails(): void
    {
        $this->put(RecordEntity::create(self::TENANT, 'a', counter: 1));

        try {
            $this->update('a', Update::increment('counter', 1), Filter::greaterThan('counter', 1));
            static::fail('The condition should have refused the update.');
        } catch (ConditionalCheckFailedException) {
            // Expected.
        }

        static::assertSame(1, $this->read('a')?->counter);
    }

    public function testAnActionOnAMissingItemFailsTheExistenceCheck(): void
    {
        // `ADD` would otherwise create the row from its key and the counter alone.
        static::expectException(ConditionalCheckFailedException::class);

        $this->update('never-written', Update::increment('counter', 1));
    }

    public function testASingleUpdateKeyedByTheEntityRefreshesWhatAnActionComputed(): void
    {
        $entity = RecordEntity::create(self::TENANT, 'a', counter: 5);
        $this->put($entity);

        $this->client()->update(new UpdateInput($entity, Update::increment('counter', 2)));

        static::assertSame(7, $entity->counter);
    }

    /**
     * A transaction returns no items, so what an action computed is read back, like a nested path is.
     */
    public function testATransactionReadsBackWhatAnActionComputed(): void
    {
        $first = RecordEntity::create(self::TENANT, 'a', counter: 1);
        $second = RecordEntity::create(self::TENANT, 'b', counter: 10);
        $this->put($first);
        $this->put($second);

        $this->client()->transactWrite(new TransactWriteInput()
            ->with(new UpdateInput($first, Update::with(Update::increment('counter'), Update::set('name', 'one'))))
            ->with(new UpdateInput($second, Update::increment('counter', 5))));

        static::assertSame(2, $first->counter);
        static::assertSame('one', $first->name);
        static::assertSame(15, $second->counter);
    }

    /**
     * The fields of an update go through the entity's normalizer like those of a put, so `null` for a
     * field the normalizer fills in is written as its default instead of removing a required field.
     */
    public function testTheNormalizerSeesTheFieldsOfAnUpdate(): void
    {
        $entity = NormalizedEntity::create(self::TENANT, 'invoice');
        $this->client()->put(new PutInput($entity));
        $this->client()->update(new UpdateInput($entity, ['label' => 'renamed']));

        $this->client()->update(new UpdateInput(new Key(NormalizedEntity::class, $entity->pk, $entity->id), ['label' => null]));

        $found = $this->client()->get(new GetInput([new Key(NormalizedEntity::class, $entity->pk, $entity->id)]))->first();
        static::assertInstanceOf(NormalizedEntity::class, $found);
        static::assertSame(NormalizedEntityNormalizer::DEFAULT_LABEL, $found->label);
    }

    /**
     * The value of a `setIfNotExists()` reaches the normalizer as the field it is for, so a normalizer that sets
     * the field on every update changes what the action stores instead of adding a second write to the path.
     */
    public function testTheNormalizerSeesTheValueASetIfNotExistsStores(): void
    {
        $entity = NormalizedEntity::create(self::TENANT, 'invoice');
        $this->client()->put(new PutInput($entity));

        $this->client()->update(new UpdateInput(
            new Key(NormalizedEntity::class, $entity->pk, $entity->id),
            Update::setIfNotExists('updatedAt', new \DateTimeImmutable('@1')),
        ));

        $found = $this->client()->get(new GetInput([new Key(NormalizedEntity::class, $entity->pk, $entity->id)]))->first();
        static::assertInstanceOf(NormalizedEntity::class, $found);
        static::assertSame(NormalizedEntityNormalizer::UPDATED_AT, $found->updatedAt?->getTimestamp());
    }

    public function testSetIfNotExistsFillsAMissingMapEntryAndKeepsAStoredOne(): void
    {
        $entity = RecordEntity::create(self::TENANT, 'a');
        $entity->meta = ['kept' => 'stored'];
        $this->put($entity);

        $this->update('a', Update::with(Update::setIfNotExists('meta.kept', 'offered'), Update::setIfNotExists('meta.filled', 'offered')));

        // A map has no order.
        static::assertEquals(['kept' => 'stored', 'filled' => 'offered'], $this->read('a')?->meta);
    }

    public function testPrependStartsAMissingList(): void
    {
        $this->put(RecordEntity::create(self::TENANT, 'a', tags: ['stale']));
        $this->removeRaw('a', 'tags');

        $this->update('a', Update::prepend('tags', ['first']));

        static::assertSame(['first'], $this->read('a')?->tags);
    }

    /**
     * Nothing to append writes nothing, so a missing list is not created as an empty one.
     */
    public function testAppendingNothingLeavesAMissingListMissing(): void
    {
        $this->put(RecordEntity::create(self::TENANT, 'a', tags: ['stale']));
        $this->removeRaw('a', 'tags');

        $this->update('a', Update::with(Update::append('tags', []), Update::set('name', 'b')));

        static::assertArrayNotHasKey('tags', $this->readRaw('a'));
    }

    public function testAnActionReachesAMapKeyDynamoDbCannotSpellLiterally(): void
    {
        $entity = RecordEntity::create(self::TENANT, 'a');
        $entity->counts = ['sub-1' => 1];
        $this->put($entity);

        $this->update('a', Update::increment('counts.sub-2/a b', 3));

        // A map has no order.
        static::assertEquals(['sub-1' => 1, 'sub-2/a b' => 3], $this->read('a')?->counts);
    }

    /**
     * An action counts a missing entry as 0 or a missing list as empty, but not the map it descends
     * through, just as a nested field cannot create it.
     */
    #[DataProvider('actionsUnderAMissingMapProvider')]
    public function testAnActionUnderAMissingMapIsRejected(UpdateExpression $update, string $map): void
    {
        $this->put(RecordEntity::create(self::TENANT, 'a'));
        $this->removeRaw('a', $map);

        static::expectException(ClientException::class);
        static::expectExceptionMessageMatches('/document path provided in the update expression is invalid/');

        $this->update('a', $update);
    }

    /**
     * @return iterable<string, array{UpdateExpression, string}>
     */
    public static function actionsUnderAMissingMapProvider(): iterable
    {
        yield 'increment' => [Update::increment('counts.seen'), 'counts'];
        yield 'append' => [Update::append('groups.g', ['one']), 'groups'];
        yield 'setIfNotExists' => [Update::setIfNotExists('meta.first', 'one'), 'meta'];
    }

    /**
     * Chaining `increment()` twice does not count twice. Each path may appear once per update, and the
     * expression passes on whatever it was given, so DynamoDB refuses the whole update.
     */
    #[DataProvider('overlappingUpdatesProvider')]
    public function testAnUpdateWritingOnePathTwiceIsRejectedAsAWhole(UpdateExpression $update): void
    {
        $entity = RecordEntity::create(self::TENANT, 'a', counter: 1);
        $entity->groups = ['g' => ['one']];
        $this->put($entity);
        $before = $this->readRaw('a');

        try {
            $this->update('a', $update);
            static::fail('DynamoDB should have refused the overlapping paths.');
        } catch (ClientException $exception) {
            static::assertMatchesRegularExpression('/Two document paths overlap/', $exception->getMessage());
        }

        static::assertEquals($before, $this->readRaw('a'));
    }

    /**
     * @return iterable<string, array{UpdateExpression}>
     */
    public static function overlappingUpdatesProvider(): iterable
    {
        yield 'the same action twice' => [Update::with(Update::increment('counter'), Update::increment('counter'))];
        yield 'a field and an action' => [Update::with(Update::set('counter', 0), Update::increment('counter'))];
        yield 'an attribute and an action on its entry' => [Update::with(Update::set('groups', []), Update::append('groups.g', ['two']))];
        yield 'an attribute and a field on its entry' => [Update::setFields(['groups' => [], 'groups.g' => ['two']])];
    }

    #[DataProvider('keyAttributeUpdatesProvider')]
    public function testAnUpdateOfAKeyAttributeIsRejected(UpdateExpression $update): void
    {
        $this->put(RecordEntity::create(self::TENANT, 'a'));

        static::expectException(ClientException::class);
        static::expectExceptionMessageMatches('/Cannot update attribute id\. This attribute is part of the key/');

        $this->update('a', $update);
    }

    /**
     * @return iterable<string, array{UpdateExpression}>
     */
    public static function keyAttributeUpdatesProvider(): iterable
    {
        yield 'a field' => [Update::set('id', 'b')];
        yield 'an action' => [Update::setIfNotExists('id', 'b')];
    }

    /**
     * `ADD` and `DELETE` take a number or a set. The bundle ships no set type, so a list serializes as a
     * list, and DynamoDB refuses it.
     */
    #[DataProvider('operandsAddAndDeleteRefuseProvider')]
    public function testAddAndDeleteRefuseAnOperandThatIsNoNumberOrSet(UpdateExpression $update): void
    {
        $this->put(RecordEntity::create(self::TENANT, 'a', name: 'stored', tags: ['x']));

        static::expectException(ClientException::class);
        static::expectExceptionMessageMatches('/Incorrect operand type for operator or function/');

        $this->update('a', $update);
    }

    /**
     * @return iterable<string, array{UpdateExpression}>
     */
    public static function operandsAddAndDeleteRefuseProvider(): iterable
    {
        yield 'ADD to a string' => [Update::addToSet('name', 'more')];
        yield 'ADD to a list' => [Update::addToSet('tags', ['y'])];
        yield 'DELETE from a list' => [Update::removeFromSet('tags', ['x'])];
    }

    /**
     * DynamoDB computes in decimal, so the three steps add up exactly, where the same sum in PHP comes
     * out as `0.30000000000000004`.
     */
    public function testAFloatCountsInDecimal(): void
    {
        $this->put(RecordEntity::create(self::TENANT, 'a'));

        $this->update('a', Update::increment('amount', 0.1));
        $this->update('a', Update::increment('amount', 0.1));
        $this->update('a', Update::increment('amount', 0.1));

        static::assertSame(0.3, $this->read('a')?->amount);
    }

    /**
     * DynamoDB counts in 38 digits, so an `ADD` past `PHP_INT_MAX` is stored whole. Only the read narrows
     * it, and saturates without an error.
     */
    public function testACountPastPhpIntMaxIsStoredWholeButReadsBackSaturated(): void
    {
        $this->put(RecordEntity::create(self::TENANT, 'a', counter: \PHP_INT_MAX));

        $this->update('a', Update::increment('counter'));

        static::assertSame('9223372036854775808', $this->readRaw('a')['counter']->getN());
        static::assertSame(\PHP_INT_MAX, $this->read('a')?->counter);
    }

    /**
     * What an action is for: two writers that read the same row both count. Writing `counter + 1` as a
     * field would store 6, since each would write what it read plus one.
     */
    public function testTwoWritersCountingFromTheSameReadBothCount(): void
    {
        $this->put(RecordEntity::create(self::TENANT, 'a', counter: 5));
        $first = $this->read('a');
        $second = $this->read('a');
        static::assertNotNull($first);
        static::assertNotNull($second);

        $this->client()->update(new UpdateInput($first, Update::increment('counter')));
        $this->client()->update(new UpdateInput($second, Update::increment('counter')));

        static::assertSame(7, $this->read('a')?->counter);
        // Refreshed from the row, so each entity holds what its own write left behind.
        static::assertSame(6, $first->counter);
        static::assertSame(7, $second->counter);
    }

    public function testTheWriterWhoseSetIfNotExistsCameSecondLearnsTheStoredValue(): void
    {
        $this->put(RecordEntity::create(self::TENANT, 'a'));
        $first = $this->read('a');
        $second = $this->read('a');
        static::assertNotNull($first);
        static::assertNotNull($second);

        $this->client()->update(new UpdateInput($first, Update::setIfNotExists('name', 'first')));
        $this->client()->update(new UpdateInput($second, Update::setIfNotExists('name', 'second')));

        static::assertSame('first', $second->name);
    }

    /**
     * The step differs from the stored number, so a collided value placeholder could not pass unnoticed:
     * the condition would require the step, or the step would be the required number.
     */
    public function testAConditionOnTheEntryAnActionCountsIsCheckedAgainstTheStoredNumber(): void
    {
        $entity = RecordEntity::create(self::TENANT, 'a');
        $entity->counts = ['seen' => 4];
        $this->put($entity);

        $this->update('a', Update::increment('counts.seen', 2), Filter::equals('counts.seen', 4));

        static::assertSame(['seen' => 6], $this->read('a')?->counts);
    }

    public function testATransactionOfActionsRollsBackWhenOneConditionFails(): void
    {
        $this->put(RecordEntity::create(self::TENANT, 'a', counter: 1));
        $this->put(RecordEntity::create(self::TENANT, 'b', counter: 10));

        try {
            $this->client()->transactWrite(new TransactWriteInput()->with(
                new UpdateInput(new Key(RecordEntity::class, self::TENANT, 'a'), Update::increment('counter')),
                new UpdateInput(new Key(RecordEntity::class, self::TENANT, 'b'), Update::increment('counter'), Filter::equals('counter', 0)),
            ));
            static::fail('The failed condition should have cancelled the transaction.');
        } catch (TransactionCanceledException) {
            // Expected.
        }

        static::assertSame(1, $this->read('a')?->counter);
        static::assertSame(10, $this->read('b')?->counter);
    }

    /**
     * `refresh: Refresh::WithoutReadBack` saves the read-back, and the entity pays for it: what was sent is applied, what
     * DynamoDB computed is not.
     */
    public function testATransactionWithoutReadbackLeavesWhatAnActionComputedStale(): void
    {
        $entity = RecordEntity::create(self::TENANT, 'a', counter: 1);
        $this->put($entity);

        $this->client()->transactWrite(new TransactWriteInput()
            ->with(new UpdateInput($entity, Update::with(Update::increment('counter'), Update::set('name', 'one')), refresh: Refresh::WithoutReadBack)));

        static::assertSame('one', $entity->name);
        static::assertSame(1, $entity->counter);
        static::assertSame(2, $this->read('a')?->counter);
    }

    /**
     * The example of an action of your own in the extending guide. DynamoDB evaluates every operand against
     * the stored item, so the copy takes the status before the same update changes it.
     */
    public function testACopyActionOfYourOwnReadsTheValueBeforeTheUpdate(): void
    {
        $this->put(RecordEntity::create(self::TENANT, 'a', status: RecordStatus::Open));

        $copy = new class('status', 'meta.previousStatus') implements UpdateActionInterface {
            public function __construct(
                private readonly string $from,
                private readonly string $to,
            ) {
            }

            public function getClause(): UpdateClause
            {
                return UpdateClause::Set;
            }

            public function compile(ExpressionCompileContext $context): string
            {
                return "{$context->path($this->to)} = {$context->path($this->from)}";
            }
        };

        $this->update('a', Update::with(Update::set('status', RecordStatus::Done), $copy));

        $found = $this->read('a');
        static::assertSame(RecordStatus::Done, $found?->status);
        static::assertSame(['previousStatus' => 'open'], $found->meta);
    }

    /**
     * An update of only actions that contribute nothing is refused while the transaction is built, so the
     * put beside it is never sent either.
     */
    public function testAnUpdateWithNothingToWriteRefusesItsWholeTransaction(): void
    {
        $this->put(RecordEntity::create(self::TENANT, 'a'));

        $nothing = new class implements UpdateActionInterface {
            public function getClause(): UpdateClause
            {
                return UpdateClause::Set;
            }

            public function compile(ExpressionCompileContext $context): ?string
            {
                return null;
            }
        };

        try {
            $this->client()->transactWrite(new TransactWriteInput()
                ->with(new PutInput(RecordEntity::create(self::TENANT, 'b')))
                ->with(new UpdateInput(new Key(RecordEntity::class, self::TENANT, 'a'), Update::with($nothing))));
            static::fail('The empty update should have been refused.');
        } catch (UpdateEmptyException) {
            // Expected.
        }

        static::assertNull($this->read('b'));
    }

    public function testAddJoinsElementsIntoASetAndStartsAMissingOne(): void
    {
        $this->putArchive(ArchiveEntity::create('x'));

        $this->updateArchive('x', Update::addToSet('labels', new StringSet('a', 'b')));
        $this->updateArchive('x', Update::addToSet('labels', new StringSet('b', 'c')));

        // A set has no order.
        static::assertEqualsCanonicalizing(['a', 'b', 'c'], $this->readArchive('x')?->labels?->values);
    }

    public function testDeleteTakesElementsOutOfASetAndSkipsAnAbsentOne(): void
    {
        $entity = ArchiveEntity::create('x');
        $entity->labels = new StringSet('a', 'b', 'c');
        $this->putArchive($entity);

        $this->updateArchive('x', Update::removeFromSet('labels', new StringSet('a', 'absent')));

        // A set has no order.
        static::assertEqualsCanonicalizing(['b', 'c'], $this->readArchive('x')?->labels?->values);
    }

    /**
     * DynamoDB stores no empty set, so taking out the last element removes the attribute, and a `DELETE`
     * from the set that is gone changes nothing.
     */
    public function testDeletingTheLastElementsRemovesTheSet(): void
    {
        $entity = ArchiveEntity::create('x');
        $entity->labels = new StringSet('a');
        $this->putArchive($entity);

        $this->updateArchive('x', Update::removeFromSet('labels', new StringSet('a')));
        static::assertNull($this->readArchive('x')?->labels);

        $this->updateArchive('x', Update::removeFromSet('labels', new StringSet('a')));
        static::assertNull($this->readArchive('x')?->labels);
    }

    public function testAddOfAnEmptySetIsRejected(): void
    {
        $this->putArchive(ArchiveEntity::create('x'));

        static::expectException(ClientException::class);
        static::expectExceptionMessageMatches('/string set\s+may not be empty/');

        $this->updateArchive('x', Update::addToSet('labels', new StringSet()));
    }

    private function put(RecordEntity $entity): void
    {
        $this->client()->put(new PutInput($entity));
    }

    /**
     * @param array<string, mixed>|UpdateExpression $update - fields keyed by path, so a key may address one map entry
     */
    private function update(string $id, array|UpdateExpression $update, ?FilterInterface $condition = null): void
    {
        $this->client()->update(new UpdateInput(new Key(RecordEntity::class, self::TENANT, $id), $update, $condition));
    }

    /**
     * Removes an attribute behind the DAL's back, as a row written before the field existed would lack it.
     */
    private function removeRaw(string $id, string $attribute): void
    {
        $this->dynamo()->updateItem([
            'TableName' => DynamoDbTestKernel::TABLES['record'],
            'Key' => [
                'tenantId' => new AttributeValue(['S' => self::TENANT]),
                'id' => new AttributeValue(['S' => $id]),
            ],
            'UpdateExpression' => 'REMOVE #attribute',
            'ExpressionAttributeNames' => ['#attribute' => $attribute],
        ])->resolve();
    }

    private function read(string $id): ?RecordEntity
    {
        $entity = $this->client()->get(new GetInput([new Key(RecordEntity::class, self::TENANT, $id)]))->first();
        static::assertTrue($entity === null || $entity instanceof RecordEntity);

        /** @var ?RecordEntity $entity */
        return $entity;
    }

    /**
     * The row as DynamoDB holds it, before a field serializer narrows or rejects a value.
     *
     * @return array<string, AttributeValue>
     */
    private function readRaw(string $id): array
    {
        return $this->dynamo()->getItem([
            'TableName' => DynamoDbTestKernel::TABLES['record'],
            'Key' => [
                'tenantId' => new AttributeValue(['S' => self::TENANT]),
                'id' => new AttributeValue(['S' => $id]),
            ],
            'ConsistentRead' => true,
        ])->getItem();
    }

    private function putArchive(ArchiveEntity $entity): void
    {
        $this->client()->put(new PutInput($entity));
    }

    private function updateArchive(string $id, UpdateExpression $update): void
    {
        $this->client()->update(new UpdateInput(new Key(ArchiveEntity::class, $id), $update));
    }

    private function readArchive(string $id): ?ArchiveEntity
    {
        $entity = $this->client()->get(new GetInput([new Key(ArchiveEntity::class, $id)]))->first();
        static::assertTrue($entity === null || $entity instanceof ArchiveEntity);

        /** @var ?ArchiveEntity $entity */
        return $entity;
    }
}
