<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Integration\DynamoDb;

use AsyncAws\Core\Exception\Http\ClientException;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;
use AsyncAws\DynamoDb\Exception\ConditionalCheckFailedException;
use PHPUnit\Framework\Attributes\CoversClass;
use Shopware\DynamodbDalBundle\Client\Index;
use Shopware\DynamodbDalBundle\Client\Input\GetInput;
use Shopware\DynamodbDalBundle\Client\Input\PutInput;
use Shopware\DynamodbDalBundle\Client\Input\UpdateInput;
use Shopware\DynamodbDalBundle\Expression\Contract\ExpressionInterface;
use Shopware\DynamodbDalBundle\Expression\Filter;
use Shopware\DynamodbDalBundle\Definition\FieldPath;
use Shopware\DynamodbDalBundle\Serializer\SerializedFieldResult;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\DynamoDbTestKernel;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\Entity\RecordEntity;

/**
 * What an update expression does with a {@see FieldPath}, asked of DynamoDB rather than of an
 * assertion about the string we generated.
 *
 * The subject is how the placeholders avoid colliding. An update and its condition are compiled
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
#[CoversClass(SerializedFieldResult::class)]
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
        // nothing to put the entry in, and DynamoDB refuses the whole update.
        static::expectException(ClientException::class);
        static::expectExceptionMessageMatches('/document path provided in the update expression is invalid/');

        $this->update('never-written', ['meta.first' => 'one']);
    }

    public function testUpdateOfAWholeAttributeCreatesTheMissingRow(): void
    {
        // The contrast with the nested path above: `UpdateItem` has no notion of "only if present", so a
        // write that must not resurrect a deleted row has to say so with a condition of its own.
        $this->update('absent-whole', ['meta' => ['first' => 'one']]);

        // Read raw: the row the update conjured carries only the key and what was written, so the DAL
        // would refuse to deserialize it over the required fields it is missing.
        $item = $this->dynamo()->getItem([
            'TableName' => DynamoDbTestKernel::TABLES['record'],
            'Key' => [
                'tenantId' => new AttributeValue(['S' => self::TENANT]),
                'id' => new AttributeValue(['S' => 'absent-whole']),
            ],
        ])->getItem();

        static::assertArrayHasKey('meta', $item);
        static::assertSame(['first' => ['S' => 'one']], $item['meta']->requestBody()['M'] ?? null);
        static::assertArrayNotHasKey('createdAt', $item);
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

        $this->client()->update(RecordEntity::class, new UpdateInput($entity, ['meta.first' => 'one-renewed']));

        static::assertSame(['first' => 'one-renewed', 'second' => 'two'], $entity->meta);
    }

    public function testUpdateKeyedByTheEntityRefreshesAListItReplacedOneElementOf(): void
    {
        $entity = RecordEntity::create(self::TENANT, 'a', tags: ['first', 'second']);
        $this->put($entity);

        $this->client()->update(RecordEntity::class, new UpdateInput($entity, ['tags[1]' => 'replaced']));

        static::assertSame(['first', 'replaced'], $entity->tags);
    }

    private function put(RecordEntity $entity): void
    {
        $this->client()->put(RecordEntity::class, new PutInput($entity));
    }

    /**
     * @param array<string, mixed> $fields - keyed by path, so a key may address one map entry
     */
    private function update(string $id, array $fields, ?ExpressionInterface $condition = null): void
    {
        $this->client()->update(RecordEntity::class, new UpdateInput(new Index(self::TENANT, $id), $fields, $condition));
    }

    private function read(string $id): ?RecordEntity
    {
        $entity = $this->client()->get(new GetInput([RecordEntity::class => [new Index(self::TENANT, $id)]]))->first();
        static::assertTrue($entity === null || $entity instanceof RecordEntity);

        /** @var ?RecordEntity $entity */
        return $entity;
    }
}
