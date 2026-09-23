<?php

declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Command;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use AsyncAws\DynamoDb\DynamoDbClient;
use AsyncAws\DynamoDb\ValueObject\KeySchemaElement;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Outputs the current DynamoDB key/index schema from the live table (describeTable) as JSON.
 * Diffed against a committed copy, it flags a key or index change on a table.
 *
 * @codeCoverageIgnore
 */
#[AsCommand(
    name: 'dal:baseline:table-schema',
    description: 'Output DynamoDB table key and GSI schema from live tables as JSON baseline',
)]
class DALBaselineTableSchemaCommand
{
    /**
     * @param iterable<string, EntityDefinition<AbstractEntity>> $entityDefinitions
     */
    public function __construct(
        private readonly iterable $entityDefinitions,
        private readonly DynamoDbClient $dynamoDbClient,
    ) {
    }

    public function __invoke(SymfonyStyle $io, OutputInterface $output): int
    {
        $definitionsByName = $this->getDefinitionsByName();

        $actual = $this->buildSchemaFromTables($definitionsByName, $io);
        if ($actual === null) {
            return Command::FAILURE;
        }

        // Output the baseline JSON only, so it can be written to a file and diffed.
        $output->writeln(json_encode($actual, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    }

    /**
     * @return array<string, EntityDefinition<AbstractEntity>>
     */
    private function getDefinitionsByName(): array
    {
        $byName = [];
        foreach ($this->entityDefinitions as $definition) {
            if (!$definition instanceof EntityDefinition) {
                continue;
            }

            $byName[$definition->getName()] = $definition;
        }

        ksort($byName);

        return $byName;
    }

    /**
     * @param array<string, EntityDefinition<AbstractEntity>> $definitionsByName
     *
     * @return array<string, array{hashKey: string, rangeKey: string|null, globalSecondaryIndexes: array<string, array{hashKey: string, rangeKey: string|null}>}>|null
     */
    private function buildSchemaFromTables(array $definitionsByName, SymfonyStyle $io): ?array
    {
        $actual = [];
        foreach ($definitionsByName as $entityName => $definition) {
            $result = $this->dynamoDbClient->describeTable([
                'TableName' => $definition->getTable(),
            ]);
            $result->resolve();
            $table = $result->getTable();
            if ($table === null) {
                $io->getErrorStyle()->error("Table for definition {$definition->getTable()} does not exist.");

                return null;
            }

            $gsi = [];
            foreach ($table->getGlobalSecondaryIndexes() as $index) {
                $indexName = $index->getIndexName();
                if ($indexName === null || $indexName === '') {
                    $io->getErrorStyle()->error("Table {$definition->getTable()} has a GSI with no name.");

                    return null;
                }

                $gsi[$indexName] = $this->extractHashAndRangeFromKeySchema($index->getKeySchema());
            }

            $actual[$entityName] = [
                ...$this->extractHashAndRangeFromKeySchema($table->getKeySchema()),
                'globalSecondaryIndexes' => $gsi,
            ];
        }

        return $this->rangeKeysRecursive($actual);
    }

    /**
     * @param KeySchemaElement[] $keySchema
     *
     * @return array{hashKey: string|null, rangeKey: string|null}
     */
    private function extractHashAndRangeFromKeySchema(array $keySchema): array
    {
        $hashKey = null;
        $rangeKey = null;
        foreach ($keySchema as $element) {
            match ($element->getKeyType()) {
                'HASH' => $hashKey = $element->getAttributeName(),
                'RANGE' => $rangeKey = $element->getAttributeName(),
                default => null,
            };
        }

        return [
            'hashKey' => $hashKey,
            'rangeKey' => $rangeKey,
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function rangeKeysRecursive(array $data): array
    {
        ksort($data);
        foreach (array_keys($data) as $key) {
            if (\is_array($data[$key])) {
                $data[$key] = $this->rangeKeysRecursive($data[$key]);
            }
        }

        return $data;
    }
}
