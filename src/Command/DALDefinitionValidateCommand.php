<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Command;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\IndexSchema;
use Shopware\DynamodbDalBundle\Definition\KeySchema;
use AsyncAws\DynamoDb\DynamoDbClient;
use AsyncAws\DynamoDb\Enum\KeyType;
use AsyncAws\DynamoDb\Enum\ProjectionType;
use AsyncAws\DynamoDb\Exception\ResourceNotFoundException;
use AsyncAws\DynamoDb\ValueObject\KeySchemaElement;
use AsyncAws\DynamoDb\ValueObject\Projection;
use AsyncAws\DynamoDb\ValueObject\TableDescription;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * @internal
 */
#[AsCommand(
    name: 'dal:definition:validate',
    description: 'Compare every DAL definition with its live DynamoDB table',
    help: <<<'HELP'
            The <info>%command.name%</info> command describes the table of every entity and compares it with the
            compiled definition. It fails where the table differs in a way that makes requests fail:

              * the table does not exist
              * the hash or range key of the table or of a declared index is another attribute than the definition's
              * a key attribute is of another type than its field is stored as
              * a declared index does not exist, or does not project every field, so an entity read from it lacks some

            An index the table has but the definition does not declare is a warning only: the bundle refuses to
            search it, but writes keep it up to date, so it may well serve another reader.

            The command sends one <info>DescribeTable</info> request per entity, so it needs the credentials to
            describe every table. <info>dal:baseline:dump</info> records the definitions without DynamoDB.
            HELP
)]
readonly class DALDefinitionValidateCommand
{
    /**
     * @internal
     *
     * @param iterable<string, EntityDefinition<AbstractEntity>> $entityDefinitions
     */
    public function __construct(
        private iterable $entityDefinitions,
        private DynamoDbClient $dynamoDbClient,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $definitions = [];
        foreach ($this->entityDefinitions as $definition) {
            $definitions[$definition->getName()] = $definition;
        }

        ksort($definitions);

        $rows = [];
        $failed = 0;
        foreach ($definitions as $name => $definition) {
            [$errors, $warnings] = $this->validate($definition);
            if ($errors !== []) {
                ++$failed;
            }

            $result = [
                ...array_map(static fn (string $error): string => '<fg=red>error</>: ' . OutputFormatter::escape($error), $errors),
                ...array_map(static fn (string $warning): string => '<fg=yellow>warning</>: ' . OutputFormatter::escape($warning), $warnings),
            ];

            $rows[] = [$name, $definition->getTable(), $result === [] ? '<info>ok</info>' : implode("\n", $result)];
        }

        $io->table(['entity', 'table', 'result'], $rows);

        if ($failed > 0) {
            $io->error(\sprintf('%d of %d tables do not match their definitions.', $failed, \count($definitions)));

            return Command::FAILURE;
        }

        $io->success(\sprintf('%d tables match their definitions.', \count($definitions)));

        return Command::SUCCESS;
    }

    /**
     * @param EntityDefinition<AbstractEntity> $definition
     *
     * @return array{list<string>, list<string>} - the errors, then the warnings
     */
    private function validate(EntityDefinition $definition): array
    {
        try {
            $table = $this->dynamoDbClient->describeTable(['TableName' => $definition->getTable()])->getTable();
        } catch (ResourceNotFoundException) {
            $table = null;
        }

        if ($table === null) {
            return [['the table does not exist'], []];
        }

        $errors = [
            ...$this->compareKeySchema('the table', $definition->getKeySchema(), $table->getKeySchema()),
            ...$this->compareAttributeTypes($definition, $table),
        ];

        $described = [];
        foreach ([...$table->getGlobalSecondaryIndexes(), ...$table->getLocalSecondaryIndexes()] as $index) {
            $described[(string) $index->getIndexName()] = $index;
        }

        foreach ($definition->getIndexes() as $name => $index) {
            $subject = "index \"{$name}\"";

            $describedIndex = $described[$name] ?? null;
            if ($describedIndex === null) {
                $errors[] = "{$subject} does not exist";

                continue;
            }

            $errors = [
                ...$errors,
                ...$this->compareKeySchema($subject, $index->keySchema, $describedIndex->getKeySchema()),
                ...$this->compareProjection($subject, $definition, $index, $describedIndex->getProjection()),
            ];
        }

        $warnings = [];
        foreach (array_keys(array_diff_key($described, $definition->getIndexes())) as $name) {
            $warnings[] = "index \"{$name}\" is not declared, so it cannot be searched";
        }

        return [$errors, $warnings];
    }

    /**
     * @param KeySchemaElement[] $described
     *
     * @return list<string>
     */
    private function compareKeySchema(string $subject, KeySchema $declared, array $described): array
    {
        $attributes = [KeyType::HASH => null, KeyType::RANGE => null];
        foreach ($described as $element) {
            $attributes[$element->getKeyType()] = $element->getAttributeName();
        }

        $keys = [
            'hash key' => [$declared->hashKey, $attributes[KeyType::HASH]],
            'range key' => [$declared->rangeKey, $attributes[KeyType::RANGE]],
        ];

        $errors = [];
        foreach ($keys as $role => [$field, $attribute]) {
            if ($field === $attribute) {
                continue;
            }

            $errors[] = match (true) {
                $attribute === null => "{$subject} has no {$role} \"{$field}\"",
                $field === null => "{$subject} has {$role} \"{$attribute}\", which the definition does not declare",
                default => "{$subject} has {$role} \"{$attribute}\" instead of \"{$field}\"",
            };
        }

        return $errors;
    }

    /**
     * Compares the type of every key attribute, of the table and of any of its indexes, with the type its field is
     * stored as. DynamoDB rejects a write whose value of a key attribute is of another type, whether the definition
     * declares the index or not.
     *
     * @param EntityDefinition<AbstractEntity> $definition
     *
     * @return list<string>
     */
    private function compareAttributeTypes(EntityDefinition $definition, TableDescription $table): array
    {
        $errors = [];
        foreach ($table->getAttributeDefinitions() as $attribute) {
            $name = $attribute->getAttributeName();
            $storedAs = $definition->getFieldDefinition($name)?->getAttributeType()->value;

            if ($storedAs !== null && $storedAs !== $attribute->getAttributeType()) {
                $errors[] = "key \"{$name}\" is of type {$attribute->getAttributeType()}, but its field is stored as {$storedAs}";
            }
        }

        return $errors;
    }

    /**
     * An entity read from an index holds only what the index projects. A missing required field fails the read, and
     * any other missing field reads as null or its default, which a put of the entity then stores.
     *
     * @param EntityDefinition<AbstractEntity> $definition
     *
     * @return list<string>
     */
    private function compareProjection(string $subject, EntityDefinition $definition, IndexSchema $index, ?Projection $projection): array
    {
        $type = $projection?->getProjectionType();
        if ($projection === null || $type === null || $type === ProjectionType::ALL) {
            return [];
        }

        $projected = [
            ...$definition->getKeySchema()->getFields(),
            ...$index->keySchema->getFields(),
            ...($type === ProjectionType::INCLUDE ? $projection->getNonKeyAttributes() : []),
        ];

        $missing = array_diff($definition->getFieldNames(), $projected);
        if ($missing === []) {
            return [];
        }

        $fields = implode(', ', array_map(static fn (string $field): string => "\"{$field}\"", $missing));

        return ["{$subject} does not project {$fields}, so an entity read from it lacks them"];
    }
}
