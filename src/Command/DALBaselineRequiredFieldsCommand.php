<?php

declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Command;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Outputs the current required-field names per DAL entity as JSON baseline.
 * Diffed against a committed copy, it flags a field turning required that stored rows may lack.
 *
 * @internal
 *
 * @codeCoverageIgnore
 */
#[AsCommand(
    name: 'dal:baseline:required-fields',
    description: 'Output required field names per DAL entity as JSON baseline',
)]
class DALBaselineRequiredFieldsCommand
{
    /**
     * @param iterable<string, EntityDefinition<AbstractEntity>> $entityDefinitions
     */
    public function __construct(
        private readonly iterable $entityDefinitions,
    ) {
    }

    public function __invoke(OutputInterface $output): int
    {
        $baseline = [];
        foreach ($this->entityDefinitions as $definition) {
            if (!$definition instanceof EntityDefinition) {
                continue;
            }

            $fields = $this->getRequiredFields($definition);
            sort($fields);
            $baseline[$definition->getName()] = $fields;
        }

        ksort($baseline);

        $json = json_encode($baseline, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
        $output->writeln($json);

        return Command::SUCCESS;
    }

    /**
     * @param EntityDefinition<AbstractEntity> $definition
     *
     * @return list<string>
     */
    private function getRequiredFields(EntityDefinition $definition): array
    {
        $requiredFields = [];
        foreach ($definition->getFieldDefinitions() as $fieldName => $fieldDefinition) {
            if (!$fieldDefinition->allowsNull() && !$fieldDefinition->hasDefaultValue()) {
                $requiredFields[] = $fieldName;
            }
        }

        return $requiredFields;
    }
}
