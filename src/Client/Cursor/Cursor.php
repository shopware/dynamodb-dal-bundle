<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Client\Cursor;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Client\Index;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * A single resume position inside a table or one of its indexes — "continue the search after this item"
 */
#[Exclude]
final class Cursor
{
    public function __construct(
        public readonly string $table,
        public readonly Index $primaryKey,
        public readonly ?Index $indexKey = null,
    ) {
    }

    /**
     * @template Entity of AbstractEntity
     *
     * @param EntityDefinition<Entity> $definition
     * @param Entity|array<string, mixed> $source
     */
    public static function from(EntityDefinition $definition, AbstractEntity|array $source, ?string $index = null): self
    {
        $fields = $source instanceof AbstractEntity ? $source->getVars() : $source;

        $keySchema = $definition->getKeySchema();
        $primaryKey = new Index(
            $fields[$keySchema->hashKey] ?? null,
            $keySchema->rangeKey !== null ? ($fields[$keySchema->rangeKey] ?? null) : null,
        );

        $indexKey = null;
        if ($index !== null && ($indexDefinition = $definition->getIndex($index)) !== null) {
            $indexSchema = $indexDefinition->keySchema;
            $indexKey = new Index(
                $fields[$indexSchema->hashKey] ?? null,
                $indexSchema->rangeKey !== null ? ($fields[$indexSchema->rangeKey] ?? null) : null,
                $index,
            );
        }

        return new self($definition->getName(), $primaryKey, $indexKey);
    }
}
