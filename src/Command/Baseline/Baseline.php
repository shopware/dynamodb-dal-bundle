<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Command\Baseline;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\KeySchema;

/**
 * What the stored rows of every entity depend on: the keys of its table and indexes, and the attribute type of every
 * field and whether it is required. `dal:baseline:dump` prints it as JSON, and `dal:baseline:compare` reads that back
 * to tell what changed.
 *
 * @internal
 */
final readonly class Baseline
{
    /**
     * @param array<string, EntityBaseline> $entities - keyed by logical name
     */
    public function __construct(
        public array $entities,
    ) {
    }

    /**
     * @param iterable<EntityDefinition<AbstractEntity>> $definitions
     */
    public static function fromDefinitions(iterable $definitions): self
    {
        $entities = [];
        foreach ($definitions as $definition) {
            $entities[$definition->getName()] = EntityBaseline::fromDefinition($definition);
        }

        ksort($entities);

        return new self($entities);
    }

    /**
     * Reads a baseline back from the JSON {@see toJson()} writes.
     *
     * @throws \UnexpectedValueException if the JSON is invalid, or not shaped like a baseline
     */
    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \UnexpectedValueException("The baseline is not valid JSON: {$e->getMessage()}", 0, $e);
        }

        $entities = [];
        foreach (self::map($data, 'The baseline') as $name => $entity) {
            $entities[(string) $name] = self::parseEntity($entity, "`{$name}`");
        }

        return new self($entities);
    }

    /**
     * Entities, indexes and fields in the order they are given, which {@see fromDefinitions()} sorts by name, so the
     * output of two builds differs only where a definition does.
     *
     * @throws \JsonException
     */
    public function toJson(): string
    {
        $entities = [];
        foreach ($this->entities as $name => $entity) {
            $entities[$name] = [
                ...self::keySchemaToArray($entity->keySchema),
                // Objects, so that an empty map prints `{}` like the map a filled one prints
                'indexes' => (object) array_map(self::keySchemaToArray(...), $entity->indexes),
                'fields' => (object) array_map(self::fieldToArray(...), $entity->fields),
            ];
        }

        return json_encode((object) $entities, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<BaselineChange> - what changed from `$previous` to this baseline, sorted by entity name
     */
    public function changesSince(self $previous): array
    {
        $names = array_map(strval(...), array_keys($previous->entities + $this->entities));
        sort($names);

        $changes = [];
        foreach ($names as $name) {
            $before = $previous->entities[$name] ?? null;
            $after = $this->entities[$name] ?? null;

            if ($after === null) {
                $changes[] = new BaselineChange(
                    $name,
                    ChangeRisk::Safe,
                    'entity removed',
                    'Nothing reads its table any more. The table and its rows stay until you delete them.',
                );

                continue;
            }

            if ($before === null) {
                $changes[] = new BaselineChange(
                    $name,
                    ChangeRisk::Caution,
                    'entity added',
                    'Its table has to exist before this is deployed. `dal:definition:validate` checks that it does, and that it matches.',
                );

                continue;
            }

            array_push($changes, ...$after->changesSince($before, $name));
        }

        return $changes;
    }

    /**
     * @return array{hashKey: string, rangeKey: string|null}
     */
    private static function keySchemaToArray(KeySchema $keySchema): array
    {
        return [
            'hashKey' => $keySchema->hashKey,
            'rangeKey' => $keySchema->rangeKey,
        ];
    }

    /**
     * The attribute type of the values of a list or map nests under `values`, down to the innermost level.
     *
     * @return array<string, mixed>
     */
    private static function fieldToArray(FieldBaseline $field): array
    {
        $values = [];
        foreach (array_reverse($field->valueTypes) as $type) {
            $values = ['values' => ['type' => $type, ...$values]];
        }

        return ['type' => $field->type, 'required' => $field->required, ...$values];
    }

    /**
     * @throws \UnexpectedValueException
     */
    private static function parseEntity(mixed $data, string $path): EntityBaseline
    {
        $entity = self::map($data, $path);

        $indexes = [];
        foreach (self::map($entity['indexes'] ?? null, "{$path} indexes") as $name => $index) {
            $indexes[(string) $name] = self::parseKeySchema($index, "{$path} index `{$name}`");
        }

        $fields = [];
        foreach (self::map($entity['fields'] ?? null, "{$path} fields") as $name => $field) {
            $fields[(string) $name] = self::parseField($field, "{$path} field `{$name}`");
        }

        return new EntityBaseline(self::parseKeySchema($entity, $path), $indexes, $fields);
    }

    /**
     * @throws \UnexpectedValueException
     */
    private static function parseKeySchema(mixed $data, string $path): KeySchema
    {
        $keySchema = self::map($data, $path);

        $hashKey = $keySchema['hashKey'] ?? null;
        if (!\is_string($hashKey)) {
            throw new \UnexpectedValueException("{$path} has no hashKey string");
        }

        $rangeKey = $keySchema['rangeKey'] ?? null;
        if ($rangeKey !== null && !\is_string($rangeKey)) {
            throw new \UnexpectedValueException("{$path} has a rangeKey that is neither a string nor null");
        }

        return new KeySchema($hashKey, $rangeKey);
    }

    /**
     * @throws \UnexpectedValueException
     */
    private static function parseField(mixed $data, string $path): FieldBaseline
    {
        $field = self::map($data, $path);

        $required = $field['required'] ?? null;
        if (!\is_bool($required)) {
            throw new \UnexpectedValueException("{$path} has no required boolean");
        }

        $type = self::parseType($field, $path);

        $valueTypes = [];
        for ($level = $field; \array_key_exists('values', $level);) {
            $path .= ' values';
            $level = self::map($level['values'], $path);
            $valueTypes[] = self::parseType($level, $path);
        }

        return new FieldBaseline($type, $required, $valueTypes);
    }

    /**
     * @param array<array-key, mixed> $level
     *
     * @throws \UnexpectedValueException
     */
    private static function parseType(array $level, string $path): string
    {
        $type = $level['type'] ?? null;
        if (!\is_string($type)) {
            throw new \UnexpectedValueException("{$path} has no type string");
        }

        return $type;
    }

    /**
     * @throws \UnexpectedValueException
     *
     * @return array<array-key, mixed>
     */
    private static function map(mixed $value, string $path): array
    {
        // `{}` decodes to `[]` as well, so only a non-empty list is no map
        if (!\is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new \UnexpectedValueException("{$path} is not an object");
        }

        return $value;
    }
}
