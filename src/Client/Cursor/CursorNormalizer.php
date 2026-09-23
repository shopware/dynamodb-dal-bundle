<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Client\Cursor;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Client\Index;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\EntityDefinitionRegistry;
use Shopware\DynamodbDalBundle\Definition\KeySchema;
use Shopware\DynamodbDalBundle\Serializer\Serializer;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;
use Symfony\Component\Serializer\Exception\InvalidArgumentException;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * (De)normalizes a {@see Cursor} across the HTTP edge (it rides the URL via `#[MapQueryString]`). A
 * cursor holds deserialized values; the DAL {@see Serializer} converts them to/from the DynamoDB scalar
 * form (`{S|N|B}`), with field names resolved from the cursor's table via the {@see EntityDefinitionRegistry}.
 *
 * @internal
 */
final class CursorNormalizer implements NormalizerInterface, DenormalizerInterface
{
    /**
     * Both dependencies are injected lazily (see the bundle's service configuration) to break a
     * construction cycle: this normalizer is part of the framework `serializer`, which the registry's
     * entity normalizers depend on.
     */
    public function __construct(
        private readonly Serializer $serializer,
        private readonly EntityDefinitionRegistry $definitionRegistry,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        if ($data instanceof CursorHistory) {
            return ['pages' => array_map(
                fn (CursorCollection $page): array => $this->normalize($page, $format, $context),
                $data->pages,
            )];
        }

        if ($data instanceof CursorCollection) {
            return ['cursors' => array_map(
                fn (Cursor $cursor): array => $this->normalize($cursor, $format, $context),
                $data->cursors,
            )];
        }

        if (!$data instanceof Cursor) {
            throw new InvalidArgumentException(\sprintf('The object must be an instance of "%s", "%s" or "%s".', Cursor::class, CursorCollection::class, CursorHistory::class));
        }

        $definition = $this->definitionRegistry->get($data->table);

        $index = null;
        if ($data->indexKey !== null && $data->indexKey->index !== null) {
            $index = [
                'name' => $data->indexKey->index,
                ...$this->scalarKey($definition, $data->indexKey),
            ];
        }

        return [
            'table' => $data->table,
            'primary' => $this->scalarKey($definition, $data->primaryKey),
            'index' => $index,
        ];
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof Cursor || $data instanceof CursorCollection || $data instanceof CursorHistory;
    }

    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): Cursor|CursorCollection|CursorHistory
    {
        // A cursor (or a nested part) may arrive as a JSON string from the URL; decode before denormalizing.
        $data = $this->decodeIfJson($data);

        if (!\is_array($data)) {
            throw new InvalidArgumentException('A cursor must be an array or a JSON string.');
        }

        if ($type === CursorHistory::class) {
            $pages = [];
            $rawPages = \is_array($data['pages'] ?? null) ? $data['pages'] : [];
            foreach ($rawPages as $pageData) {
                $page = $this->denormalize($pageData, CursorCollection::class, $format, $context);
                if ($page instanceof CursorCollection) {
                    $pages[] = $page;
                }
            }

            return new CursorHistory($pages);
        }

        if ($type === CursorCollection::class) {
            $cursors = [];
            $rawCursors = \is_array($data['cursors'] ?? null) ? $data['cursors'] : [];
            foreach ($rawCursors as $name => $cursorData) {
                $cursor = \is_string($name) ? $this->denormalize($cursorData, Cursor::class, $format, $context) : null;
                if (\is_string($name) && $cursor instanceof Cursor) {
                    $cursors[$name] = $cursor;
                }
            }

            return new CursorCollection($cursors);
        }

        if (!\is_string($data['table'] ?? null)) {
            throw new InvalidArgumentException('A cursor must be an array carrying its "table".');
        }

        $definition = $this->definitionRegistry->get($data['table']);

        $primaryKey = $this->deserializeKey($definition, $definition->getKeySchema(), $this->scalarFields($data['primary'] ?? null));

        $indexKey = null;
        $rawIndex = \is_array($data['index'] ?? null) ? $data['index'] : null;
        if ($rawIndex !== null && \is_string($rawIndex['name'] ?? null) && ($indexSchema = $definition->getIndex($rawIndex['name'])?->keySchema) !== null) {
            $indexKey = $this->deserializeKey($definition, $indexSchema, $this->scalarFields($rawIndex), $rawIndex['name']);
        }

        return new Cursor($data['table'], $primaryKey, $indexKey);
    }

    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return $type === Cursor::class || $type === CursorCollection::class || $type === CursorHistory::class;
    }

    /**
     * @return array<string, bool>
     */
    public function getSupportedTypes(?string $format): array
    {
        return [
            Cursor::class => true,
            CursorCollection::class => true,
            CursorHistory::class => true,
        ];
    }

    /**
     * An {@see Index}'s key fields as a `{fieldName: {S|N|B}}` scalar map, via the DAL serializer.
     *
     * @param EntityDefinition<AbstractEntity> $definition
     *
     * @return array<string, array<string, mixed>>
     */
    private function scalarKey(EntityDefinition $definition, Index $key): array
    {
        return array_map(
            static fn (AttributeValue $value): array => $value->requestBody(),
            $this->serializer->serialize($definition, $key->getFields($definition))->getFields(),
        );
    }

    /**
     * Rebuilds an {@see Index} from its `{fieldName: {S|N|B}}` scalar map for the given key schema.
     *
     * @param EntityDefinition<AbstractEntity> $definition
     * @param array<string, array<array-key, mixed>> $scalars
     */
    private function deserializeKey(EntityDefinition $definition, KeySchema $keySchema, array $scalars, ?string $index = null): Index
    {
        $deserialized = $this->serializer->deserializeFields($definition, array_map(
            self::toAttributeValue(...),
            $scalars,
        ));

        return new Index(
            $deserialized[$keySchema->hashKey] ?? null,
            $keySchema->rangeKey !== null ? ($deserialized[$keySchema->rangeKey] ?? null) : null,
            $index,
        );
    }

    /**
     * Decodes a valid-JSON string to its array form; passes anything else through unchanged.
     */
    private function decodeIfJson(mixed $data): mixed
    {
        if (\is_string($data) && json_validate($data)) {
            return json_decode($data, true);
        }

        return $data;
    }

    /**
     * @param mixed $fields - a `{fieldName: {S|N|B: value}}` map, or null
     *
     * @return array<string, array<array-key, mixed>>
     */
    private function scalarFields(mixed $fields): array
    {
        if (!\is_array($fields)) {
            return [];
        }

        $scalars = [];
        foreach ($fields as $name => $scalar) {
            if (\is_string($name) && \is_array($scalar)) {
                $scalars[$name] = $scalar;
            }
        }

        return $scalars;
    }

    /**
     * A key field's scalar wrapper as an {@see AttributeValue}. A DynamoDB key is always `S`, `N`
     * or `B`, so anything else a cursor carries is dropped rather than trusted: a tampered cursor
     * then fails on the value its key schema is missing.
     *
     * @param array<array-key, mixed> $scalar
     */
    private static function toAttributeValue(array $scalar): AttributeValue
    {
        $string = $scalar['S'] ?? null;
        $number = $scalar['N'] ?? null;
        $binary = $scalar['B'] ?? null;

        return AttributeValue::create([
            'S' => \is_string($string) ? $string : null,
            'N' => \is_string($number) ? $number : null,
            'B' => \is_string($binary) ? $binary : null,
        ]);
    }
}
