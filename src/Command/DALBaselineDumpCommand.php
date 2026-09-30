<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Command;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\FieldDefinition;
use Shopware\DynamodbDalBundle\Definition\KeySchema;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @internal
 */
#[AsCommand(
    name: 'dal:baseline:dump',
    description: 'Output the keys, indexes and stored field types of every DAL entity as JSON baseline',
    help: <<<'HELP'
            The <info>%command.name%</info> command prints what the stored rows of every entity depend on: its hash
            and range key, the key of each index, and for every field the attribute type it is stored as and whether
            it is required, that is neither nullable nor defaulted. The values of a list or map carry their own
            attribute type. Entities, indexes and fields are sorted by name, so the output changes only where a
            definition does.

            Commit the output and diff it in CI. A field that turns required makes every stored row that lacks it
            unreadable, and a field stored as another type fails every row stored the old way:

              <info>%command.full_name% > dal-baseline.json</info>
              <info>%command.full_name% | diff dal-baseline.json -</info>

            The output is built from the compiled definitions alone, so it needs no DynamoDB.
            <info>dal:definition:validate</info> compares the definitions with the live tables.
            HELP
)]
readonly class DALBaselineDumpCommand
{
    /**
     * @internal
     *
     * @param iterable<string, EntityDefinition<AbstractEntity>> $entityDefinitions
     */
    public function __construct(
        private iterable $entityDefinitions,
    ) {
    }

    public function __invoke(OutputInterface $output): int
    {
        $baseline = [];
        foreach ($this->entityDefinitions as $definition) {
            $baseline[$definition->getName()] = $this->describeEntity($definition);
        }

        ksort($baseline);

        // Raw, so that nothing in the JSON is taken for a style tag and it can be written to a file and diffed
        $json = json_encode($baseline, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
        $output->writeln($json, OutputInterface::OUTPUT_RAW);

        return Command::SUCCESS;
    }

    /**
     * @param EntityDefinition<AbstractEntity> $definition
     *
     * @return array<string, mixed>
     */
    private function describeEntity(EntityDefinition $definition): array
    {
        $indexes = [];
        foreach ($definition->getIndexes() as $name => $index) {
            $indexes[$name] = $this->describeKeySchema($index->keySchema);
        }

        ksort($indexes);

        $fields = [];
        foreach ($definition->getFieldDefinitions() as $name => $field) {
            $fields[$name] = [
                'type' => $field->getAttributeType()->value,
                'required' => !$field->allowsNull() && !$field->hasDefaultValue(),
                ...$this->describeValues($field),
            ];
        }

        ksort($fields);

        return [
            ...$this->describeKeySchema($definition->getKeySchema()),
            // An object, so that an entity without indexes prints `{}` like the map one with indexes prints
            'indexes' => (object) $indexes,
            'fields' => $fields,
        ];
    }

    /**
     * @return array{hashKey: string, rangeKey: string|null}
     */
    private function describeKeySchema(KeySchema $keySchema): array
    {
        return [
            'hashKey' => $keySchema->hashKey,
            'rangeKey' => $keySchema->rangeKey,
        ];
    }

    /**
     * The attribute type of the values of a list or map field, down to the innermost level. Nothing for any other
     * field. Every value is nullable, so only the type tells a stored value that no longer fits.
     *
     * @return array<string, mixed>
     */
    private function describeValues(FieldDefinition $field): array
    {
        $valueField = $field->getValueFieldDefinition();
        if ($valueField === null) {
            return [];
        }

        return [
            'values' => [
                'type' => $valueField->getAttributeType()->value,
                ...$this->describeValues($valueField),
            ],
        ];
    }
}
