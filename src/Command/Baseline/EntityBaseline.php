<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Command\Baseline;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\IndexSchema;
use Shopware\DynamodbDalBundle\Definition\KeySchema;

/**
 * @internal
 */
final readonly class EntityBaseline
{
    /**
     * @param array<string, KeySchema> $indexes - keyed by index name
     * @param array<string, FieldBaseline> $fields - keyed by field name
     */
    public function __construct(
        public KeySchema $keySchema,
        public array $indexes,
        public array $fields,
    ) {
    }

    /**
     * @param EntityDefinition<AbstractEntity> $definition
     */
    public static function fromDefinition(EntityDefinition $definition): self
    {
        $indexes = array_map(static fn (IndexSchema $index): KeySchema => $index->keySchema, $definition->getIndexes());
        ksort($indexes);

        $fields = array_map(FieldBaseline::fromDefinition(...), $definition->getFieldDefinitions());
        ksort($fields);

        return new self($definition->getKeySchema(), $indexes, $fields);
    }

    /**
     * @return list<BaselineChange> - the table key first, then the indexes, then the fields, each sorted by name
     */
    public function changesSince(self $previous, string $entity): array
    {
        return [
            ...$this->compareTableKey($previous->keySchema, $entity),
            ...$this->compareIndexes($previous->indexes, $entity),
            ...$this->compareFields($previous->fields, $entity),
        ];
    }

    /**
     * @return list<BaselineChange>
     */
    private function compareTableKey(KeySchema $previous, string $entity): array
    {
        if (self::describeKeySchema($previous) === self::describeKeySchema($this->keySchema)) {
            return [];
        }

        return [new BaselineChange(
            $entity,
            ChangeRisk::Breaking,
            \sprintf('table key %s → %s', self::describeKeySchema($previous), self::describeKeySchema($this->keySchema)),
            'DynamoDB cannot change the key of a table, so every key read and write fails against the existing one. The table has to be replaced, and its rows copied over.',
        )];
    }

    /**
     * @param array<string, KeySchema> $previous
     *
     * @return list<BaselineChange>
     */
    private function compareIndexes(array $previous, string $entity): array
    {
        $changes = [];
        foreach (self::unionOfKeys($previous, $this->indexes) as $name) {
            $before = $previous[$name] ?? null;
            $after = $this->indexes[$name] ?? null;

            $changes[] = match (true) {
                $before === null => new BaselineChange(
                    $entity,
                    ChangeRisk::Caution,
                    "index `{$name}` added",
                    'The table needs the index before this is deployed, or every search of it fails. A row that lacks one of its key attributes is not in it.',
                ),
                $after === null => new BaselineChange(
                    $entity,
                    ChangeRisk::Safe,
                    "index `{$name}` removed",
                    'The table keeps the index, and every write keeps it up to date, until you delete it.',
                ),
                self::describeKeySchema($before) !== self::describeKeySchema($after) => new BaselineChange(
                    $entity,
                    ChangeRisk::Breaking,
                    \sprintf('index `%s` key %s → %s', $name, self::describeKeySchema($before), self::describeKeySchema($after)),
                    'DynamoDB cannot change the key of an index. It has to be deleted and created again, and every search of it fails until the new one is active.',
                ),
                default => null,
            };
        }

        return array_values(array_filter($changes));
    }

    /**
     * @param array<string, FieldBaseline> $previous
     *
     * @return list<BaselineChange>
     */
    private function compareFields(array $previous, string $entity): array
    {
        $changes = [];
        foreach (self::unionOfKeys($previous, $this->fields) as $name) {
            $before = $previous[$name] ?? null;
            $after = $this->fields[$name] ?? null;

            if ($after === null) {
                $changes[] = new BaselineChange(
                    $entity,
                    ChangeRisk::Caution,
                    "field `{$name}` removed",
                    'Stored rows keep its value until a put writes them again, which drops it. Rolled back, the field is missing in every row written since.',
                );

                continue;
            }

            if ($before === null) {
                $changes[] = $after->required
                    ? new BaselineChange(
                        $entity,
                        ChangeRisk::Breaking,
                        "field `{$name}` added, required",
                        'Every stored row lacks it, so reading any of them fails with `FieldMissingDeserializedValueException`, unless the entity\'s normalizer fills it in.',
                    )
                    : new BaselineChange(
                        $entity,
                        ChangeRisk::Safe,
                        "field `{$name}` added",
                        'Stored rows lack it until a put writes them again. They read it as null or its default, but match no filter or key condition on it, and are missing from any index keyed on it.',
                    );

                continue;
            }

            if ($before->describeType() !== $after->describeType()) {
                $changes[] = new BaselineChange(
                    $entity,
                    ChangeRisk::Breaking,
                    "field `{$name}` stored as `{$before->describeType()}` → `{$after->describeType()}`",
                    "Reading a row that holds it as `{$before->describeType()}` fails with `MissingAttributeValueException`, and filters and conditions on it no longer match such a row, until a put writes it again.",
                );
            }

            if (!$before->required && $after->required) {
                $changes[] = new BaselineChange(
                    $entity,
                    ChangeRisk::Breaking,
                    "field `{$name}` is now required",
                    'Reading a stored row that lacks it or holds null fails with `FieldMissingDeserializedValueException`, unless the entity\'s normalizer fills it in.',
                );
            }

            if ($before->required && !$after->required) {
                $changes[] = new BaselineChange(
                    $entity,
                    ChangeRisk::Safe,
                    "field `{$name}` is no longer required",
                    'Every stored row reads as before.',
                );
            }
        }

        return $changes;
    }

    /**
     * The hash key and the range key, if any, e.g. `(tenantId, id)`.
     */
    private static function describeKeySchema(KeySchema $keySchema): string
    {
        return '`(' . implode(', ', $keySchema->getFields()) . ')`';
    }

    /**
     * @param array<string, mixed> $previous
     * @param array<string, mixed> $current
     *
     * @return list<string> - sorted
     */
    private static function unionOfKeys(array $previous, array $current): array
    {
        $names = array_map(strval(...), array_keys($previous + $current));
        sort($names);

        return $names;
    }
}
