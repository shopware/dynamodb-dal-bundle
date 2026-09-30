<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Command;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Command\Baseline\Baseline;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
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

            Commit the output, and compare it with the definitions of a later change. A field that turns required
            makes every stored row that lacks it unreadable, and a field stored as another type fails every row
            stored the old way:

              <info>%command.full_name% > dal-baseline.json</info>
              <info>git show origin/trunk:dal-baseline.json | php bin/console dal:baseline:compare -</info>

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
        // Raw, so that nothing in the JSON is taken for a style tag and it can be written to a file and diffed
        $output->writeln(Baseline::fromDefinitions($this->entityDefinitions)->toJson(), OutputInterface::OUTPUT_RAW);

        return Command::SUCCESS;
    }
}
