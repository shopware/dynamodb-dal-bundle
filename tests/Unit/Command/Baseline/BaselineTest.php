<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Command\Baseline;

use Shopware\DynamodbDalBundle\Command\Baseline\Baseline;
use Shopware\DynamodbDalBundle\Command\Baseline\BaselineChange;
use Shopware\DynamodbDalBundle\Command\Baseline\ChangeRisk;
use Shopware\DynamodbDalBundle\Command\Baseline\EntityBaseline;
use Shopware\DynamodbDalBundle\Command\Baseline\FieldBaseline;
use Shopware\DynamodbDalBundle\Tests\Unit\Fixtures\CommandDefinitions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Baseline::class)]
#[CoversClass(EntityBaseline::class)]
#[CoversClass(FieldBaseline::class)]
class BaselineTest extends TestCase
{
    private const string ENTITY_ADDED = 'Its table has to exist before this is deployed. `dal:definition:validate` checks that it does, and that it matches.';

    private const string ENTITY_REMOVED = 'Nothing reads its table any more. The table and its rows stay until you delete them.';

    private const string TABLE_KEY_CHANGED = 'DynamoDB cannot change the key of a table, so every key read and write fails against the existing one. The table has to be replaced, and its rows copied over.';

    private const string INDEX_ADDED = 'The table needs the index before this is deployed, or every search of it fails. A row that lacks one of its key attributes is not in it.';

    private const string INDEX_REMOVED = 'The table keeps the index, and every write keeps it up to date, until you delete it.';

    private const string INDEX_KEY_CHANGED = 'DynamoDB cannot change the key of an index. It has to be deleted and created again, and every search of it fails until the new one is active.';

    private const string REQUIRED_FIELD_ADDED = 'Every stored row lacks it, so reading any of them fails with `FieldMissingDeserializedValueException`, unless the entity\'s normalizer fills it in.';

    private const string OPTIONAL_FIELD_ADDED = 'Stored rows lack it until a put writes them again. They read it as null or its default, but match no filter or key condition on it, and are missing from any index keyed on it.';

    private const string FIELD_REMOVED = 'Stored rows keep its value until a put writes them again, which drops it. Rolled back, the field is missing in every row written since.';

    private const string FIELD_NOW_REQUIRED = 'Reading a stored row that lacks it or holds null fails with `FieldMissingDeserializedValueException`, unless the entity\'s normalizer fills it in.';

    private const string FIELD_NO_LONGER_REQUIRED = 'Every stored row reads as before.';

    public function testReadsBackTheJsonItWrites(): void
    {
        $baseline = self::current();
        $json = $baseline->toJson();

        static::assertSame($json, Baseline::fromJson($json)->toJson());
        static::assertSame([], $baseline->changesSince(Baseline::fromJson($json)));
    }

    public function testWritesAnEmptyBaselineAsAnObjectAndReadsEitherBack(): void
    {
        static::assertSame('{}', new Baseline([])->toJson());
        static::assertSame([], Baseline::fromJson('{}')->entities);
        static::assertSame([], Baseline::fromJson('[]')->entities);
    }

    /**
     * JSON object keys that are numbers decode to integer array keys, which have to come back as the names they were.
     */
    public function testKeepsNamesThatAreNumbers(): void
    {
        $earlier = Baseline::fromJson('{"2024": {"hashKey": "id", "rangeKey": null, "indexes": {"123": {"hashKey": "id", "rangeKey": null}}, "fields": {"id": {"type": "S", "required": true}}}}');
        $current = Baseline::fromJson('{"2024": {"hashKey": "id", "rangeKey": null, "indexes": {}, "fields": {"id": {"type": "S", "required": true}}}}');

        static::assertSame(['2024'], array_map(strval(...), array_keys($earlier->entities)));

        $changes = $current->changesSince($earlier);
        static::assertCount(1, $changes);
        static::assertSame('2024', $changes[0]->entity);
        static::assertSame('index `123` removed', $changes[0]->change);
    }

    public function testDescribesTheTypeOfEveryLevel(): void
    {
        static::assertSame('M<L<S>>', self::current()->entities['order']->fields['groups']->describeType());
        static::assertSame('N', self::current()->entities['order']->fields['revision']->describeType());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidJsonProvider(): iterable
    {
        yield 'no JSON' => ['{', 'The baseline is not valid JSON'];
        yield 'a list' => ['[1]', 'The baseline is not an object'];
        yield 'an entity that is no object' => ['{"order": "x"}', '`order` is not an object'];
        yield 'no fields' => ['{"order": {"hashKey": "id", "rangeKey": null, "indexes": {}}}', '`order` fields is not an object'];
        yield 'no hash key' => ['{"order": {"indexes": {}, "fields": {}}}', '`order` has no hashKey string'];
        yield 'a range key of another type' => ['{"order": {"hashKey": "id", "rangeKey": 1, "indexes": {}, "fields": {}}}', '`order` has a rangeKey that is neither a string nor null'];
        yield 'an index without hash key' => ['{"order": {"hashKey": "id", "indexes": {"byName": {}}, "fields": {}}}', '`order` index `byName` has no hashKey string'];
        yield 'a field without required' => ['{"order": {"hashKey": "id", "indexes": {}, "fields": {"id": {"type": "S"}}}}', '`order` field `id` has no required boolean'];
        yield 'a field without type' => ['{"order": {"hashKey": "id", "indexes": {}, "fields": {"id": {"required": true}}}}', '`order` field `id` has no type string'];
        yield 'values without type' => ['{"order": {"hashKey": "id", "indexes": {}, "fields": {"tags": {"type": "L", "required": false, "values": {}}}}}', '`order` field `tags` values has no type string'];
    }

    #[DataProvider('invalidJsonProvider')]
    public function testRefusesJsonThatIsNoBaseline(string $json, string $message): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage($message);

        Baseline::fromJson($json);
    }

    /**
     * Edits of the earlier baseline, as the array its JSON decodes to, and the changes they make the current one show.
     *
     * @return iterable<string, array{\Closure(array<array-key, mixed>): array<array-key, mixed>, list<array{ChangeRisk, string, string, string}>}>
     */
    public static function changeProvider(): iterable
    {
        yield 'an entity added' => [
            static fn (array $baseline): array => self::remove($baseline, ['config']),
            [[ChangeRisk::Caution, 'config', 'entity added', self::ENTITY_ADDED]],
        ];

        yield 'an entity removed' => [
            static fn (array $baseline): array => self::edit($baseline, ['archive'], ['hashKey' => 'id', 'rangeKey' => null, 'indexes' => [], 'fields' => []]),
            [[ChangeRisk::Safe, 'archive', 'entity removed', self::ENTITY_REMOVED]],
        ];

        yield 'a range key added to the table' => [
            static fn (array $baseline): array => self::edit($baseline, ['order', 'rangeKey'], null),
            [[ChangeRisk::Breaking, 'order', 'table key `(tenantId)` → `(tenantId, externalId)`', self::TABLE_KEY_CHANGED]],
        ];

        yield 'a range key removed from the table' => [
            static fn (array $baseline): array => self::edit($baseline, ['config', 'rangeKey'], 'id'),
            [[ChangeRisk::Breaking, 'config', 'table key `(tenantId, id)` → `(tenantId)`', self::TABLE_KEY_CHANGED]],
        ];

        yield 'a hash key changed' => [
            static fn (array $baseline): array => self::edit($baseline, ['config', 'hashKey'], 'id'),
            [[ChangeRisk::Breaking, 'config', 'table key `(id)` → `(tenantId)`', self::TABLE_KEY_CHANGED]],
        ];

        yield 'an index added' => [
            static fn (array $baseline): array => self::remove($baseline, ['order', 'indexes', 'revisionIndex']),
            [[ChangeRisk::Caution, 'order', 'index `revisionIndex` added', self::INDEX_ADDED]],
        ];

        yield 'an index removed' => [
            static fn (array $baseline): array => self::edit($baseline, ['order', 'indexes', 'noteIndex'], ['hashKey' => 'note', 'rangeKey' => null]),
            [[ChangeRisk::Safe, 'order', 'index `noteIndex` removed', self::INDEX_REMOVED]],
        ];

        yield 'an index range key added' => [
            static fn (array $baseline): array => self::edit($baseline, ['order', 'indexes', 'referenceIndex', 'rangeKey'], null),
            [[ChangeRisk::Breaking, 'order', 'index `referenceIndex` key `(reference)` → `(reference, revision)`', self::INDEX_KEY_CHANGED]],
        ];

        yield 'an index hash key changed' => [
            static fn (array $baseline): array => self::edit($baseline, ['order', 'indexes', 'referenceIndex', 'hashKey'], 'note'),
            [[ChangeRisk::Breaking, 'order', 'index `referenceIndex` key `(note, revision)` → `(reference, revision)`', self::INDEX_KEY_CHANGED]],
        ];

        yield 'a required field added' => [
            static fn (array $baseline): array => self::remove($baseline, ['order', 'fields', 'reference']),
            [[ChangeRisk::Breaking, 'order', 'field `reference` added, required', self::REQUIRED_FIELD_ADDED]],
        ];

        yield 'an optional field added' => [
            static fn (array $baseline): array => self::remove($baseline, ['order', 'fields', 'note']),
            [[ChangeRisk::Safe, 'order', 'field `note` added', self::OPTIONAL_FIELD_ADDED]],
        ];

        yield 'a field removed' => [
            static fn (array $baseline): array => self::edit($baseline, ['order', 'fields', 'legacy'], ['type' => 'S', 'required' => false]),
            [[ChangeRisk::Caution, 'order', 'field `legacy` removed', self::FIELD_REMOVED]],
        ];

        yield 'a field stored as another type' => [
            static fn (array $baseline): array => self::edit($baseline, ['order', 'fields', 'revision', 'type'], 'S'),
            [[ChangeRisk::Breaking, 'order', 'field `revision` stored as `S` → `N`', self::typeChanged('S')]],
        ];

        yield 'the values of a field stored as another type' => [
            static fn (array $baseline): array => self::edit($baseline, ['order', 'fields', 'groups', 'values', 'values', 'type'], 'N'),
            [[ChangeRisk::Breaking, 'order', 'field `groups` stored as `M<L<N>>` → `M<L<S>>`', self::typeChanged('M<L<N>>')]],
        ];

        yield 'a field that became a list of its type' => [
            static fn (array $baseline): array => self::remove($baseline, ['order', 'fields', 'tags', 'values']),
            [[ChangeRisk::Breaking, 'order', 'field `tags` stored as `L` → `L<S>`', self::typeChanged('L')]],
        ];

        yield 'a field that turned required' => [
            static fn (array $baseline): array => self::edit($baseline, ['order', 'fields', 'reference', 'required'], false),
            [[ChangeRisk::Breaking, 'order', 'field `reference` is now required', self::FIELD_NOW_REQUIRED]],
        ];

        yield 'a field that is no longer required' => [
            static fn (array $baseline): array => self::edit($baseline, ['order', 'fields', 'note', 'required'], true),
            [[ChangeRisk::Safe, 'order', 'field `note` is no longer required', self::FIELD_NO_LONGER_REQUIRED]],
        ];

        yield 'a field stored as another type that also turned required' => [
            static fn (array $baseline): array => self::edit($baseline, ['order', 'fields', 'reference'], ['type' => 'N', 'required' => false]),
            [
                [ChangeRisk::Breaking, 'order', 'field `reference` stored as `N` → `S`', self::typeChanged('N')],
                [ChangeRisk::Breaking, 'order', 'field `reference` is now required', self::FIELD_NOW_REQUIRED],
            ],
        ];

        yield 'several changes, sorted by entity, then key, index and field' => [
            static fn (array $baseline): array => self::edit(
                self::remove(self::edit(self::remove($baseline, ['order', 'fields', 'note']), ['order', 'rangeKey'], null), ['order', 'indexes', 'revisionIndex']),
                ['config', 'fields', 'tenantId', 'type'],
                'N',
            ),
            [
                [ChangeRisk::Breaking, 'config', 'field `tenantId` stored as `N` → `S`', self::typeChanged('N')],
                [ChangeRisk::Breaking, 'order', 'table key `(tenantId)` → `(tenantId, externalId)`', self::TABLE_KEY_CHANGED],
                [ChangeRisk::Caution, 'order', 'index `revisionIndex` added', self::INDEX_ADDED],
                [ChangeRisk::Safe, 'order', 'field `note` added', self::OPTIONAL_FIELD_ADDED],
            ],
        ];
    }

    /**
     * @param \Closure(array<array-key, mixed>): array<array-key, mixed> $edit
     * @param list<array{ChangeRisk, string, string, string}> $expected
     */
    #[DataProvider('changeProvider')]
    public function testTellsWhatChangedSinceAnEarlierBaselineAndWhyItMatters(\Closure $edit, array $expected): void
    {
        $earlier = json_decode(self::current()->toJson(), true, flags: \JSON_THROW_ON_ERROR);
        static::assertIsArray($earlier);

        $changes = self::current()->changesSince(Baseline::fromJson(json_encode($edit($earlier), \JSON_THROW_ON_ERROR)));

        static::assertSame($expected, array_map(
            static fn (BaselineChange $change): array => [$change->risk, $change->entity, $change->change, $change->reason],
            $changes,
        ));
    }

    private static function typeChanged(string $before): string
    {
        return "Reading a row that holds it as `{$before}` fails with `MissingAttributeValueException`, and filters and conditions on it no longer match such a row, until a put writes it again.";
    }

    private static function current(): Baseline
    {
        return Baseline::fromDefinitions([CommandDefinitions::order(), CommandDefinitions::customer()]);
    }

    /**
     * @param array<array-key, mixed> $baseline
     * @param non-empty-list<string> $path
     *
     * @return array<array-key, mixed>
     */
    private static function edit(array $baseline, array $path, mixed $value): array
    {
        $key = array_shift($path);
        if ($path === []) {
            $baseline[$key] = $value;

            return $baseline;
        }

        $level = $baseline[$key] ?? [];
        $baseline[$key] = self::edit(\is_array($level) ? $level : [], $path, $value);

        return $baseline;
    }

    /**
     * @param array<array-key, mixed> $baseline
     * @param non-empty-list<string> $path
     *
     * @return array<array-key, mixed>
     */
    private static function remove(array $baseline, array $path): array
    {
        $key = array_shift($path);
        if ($path === []) {
            unset($baseline[$key]);

            return $baseline;
        }

        $level = $baseline[$key] ?? null;
        if (\is_array($level)) {
            $baseline[$key] = self::remove($level, $path);
        }

        return $baseline;
    }
}
