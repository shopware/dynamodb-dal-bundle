<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Command;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Command\Baseline\Baseline;
use Shopware\DynamodbDalBundle\Command\Baseline\BaselineChange;
use Shopware\DynamodbDalBundle\Command\Baseline\ChangeRisk;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * @internal
 */
#[AsCommand(
    name: 'dal:baseline:compare',
    description: 'Compare the DAL definitions with an earlier baseline and tell what each change risks',
    help: <<<'HELP'
            The <info>%command.name%</info> command compares the baseline of the current definitions, as
            <info>dal:baseline:dump</info> prints it, with an earlier one, and lists every change with what it risks
            once it is deployed:

              <info>breaking</info>  stored rows or requests fail, such as for a field that turns required or is stored
                        as another type, or a table or index key that changes
              <info>caution</info>   a table has to change first, such as for a new index, or stored data is dropped, such
                        as a removed field's
              <info>safe</info>      nothing to do, such as for an optional field that is added

            A breaking change can be deliberate, such as a required field the entity's normalizer fills in for older
            rows, so the command fails only where it cannot read the baseline.

            Pass the earlier baseline as a file, or as <info>-</info> to read it from standard input:

              <info>git show origin/trunk:dal-baseline.json | %command.full_name% -</info>

            <info>--format=markdown</info> prints the changes for a pull request comment, and prints nothing where nothing
            changed.
            HELP
)]
readonly class DALBaselineCompareCommand
{
    private const array FORMATS = ['text', 'markdown'];

    /**
     * @internal
     *
     * @param iterable<string, EntityDefinition<AbstractEntity>> $entityDefinitions
     */
    public function __construct(
        private iterable $entityDefinitions,
    ) {
    }

    public function __invoke(
        InputInterface $input,
        OutputInterface $output,
        SymfonyStyle $io,
        #[Argument(description: 'The earlier baseline, as dal:baseline:dump printed it; - reads it from standard input')]
        string $baseline,
        #[Option(description: 'text, or markdown for a pull request comment', suggestedValues: self::FORMATS)]
        string $format = 'text',
    ): int {
        if (!\in_array($format, self::FORMATS, true)) {
            $io->error(\sprintf('The format "%s" is not one of %s.', $format, implode(', ', self::FORMATS)));

            return Command::INVALID;
        }

        $json = $this->read($input, $baseline);
        if ($json === null) {
            $io->error(\sprintf('The baseline "%s" cannot be read.', $baseline));

            return Command::FAILURE;
        }

        try {
            $previous = Baseline::fromJson($json);
        } catch (\UnexpectedValueException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $changes = Baseline::fromDefinitions($this->entityDefinitions)->changesSince($previous);

        if ($format === 'markdown') {
            if ($changes !== []) {
                $output->writeln($this->renderMarkdown($changes), OutputInterface::OUTPUT_RAW);
            }

            return Command::SUCCESS;
        }

        $this->renderText($io, $changes);

        return Command::SUCCESS;
    }

    private function read(InputInterface $input, string $baseline): ?string
    {
        if ($baseline === '-') {
            $stream = ($input instanceof StreamableInputInterface ? $input->getStream() : null) ?? \STDIN;
            $json = stream_get_contents($stream);

            return $json === false ? null : $json;
        }

        if (!is_file($baseline) || !is_readable($baseline)) {
            return null;
        }

        $json = file_get_contents($baseline);

        return $json === false ? null : $json;
    }

    /**
     * @param list<BaselineChange> $changes
     */
    private function renderText(SymfonyStyle $io, array $changes): void
    {
        if ($changes === []) {
            $io->success('Nothing changed since the baseline.');

            return;
        }

        foreach ($this->groupByRisk($changes) as [$risk, $ofRisk]) {
            $io->section(\sprintf('%s: %s', ucfirst($risk->value), lcfirst($risk->describe())));

            foreach ($ofRisk as $change) {
                $io->writeln(\sprintf(' * <info>%s</info>: %s', $change->entity, OutputFormatter::escape($change->change)));
                $io->writeln('   ' . OutputFormatter::escape($change->reason));
                $io->newLine();
            }
        }

        $breaking = $this->countBreaking($changes);
        if ($breaking > 0) {
            $io->warning(\sprintf('%d of %d changes may break stored rows or requests.', $breaking, \count($changes)));
        }
    }

    /**
     * @param non-empty-list<BaselineChange> $changes
     */
    private function renderMarkdown(array $changes): string
    {
        $breaking = $this->countBreaking($changes);

        $lines = [
            '### DAL baseline changes',
            '',
            $breaking > 0
                ? \sprintf('The definitions change what stored rows depend on. **%d of %d changes may break stored rows or requests.**', $breaking, \count($changes))
                : 'The definitions change what stored rows depend on, but nothing that breaks them.',
        ];

        foreach ($this->groupByRisk($changes) as [$risk, $ofRisk]) {
            $table = ['| Entity | Change | Why |', '|---|---|---|'];
            foreach ($ofRisk as $change) {
                $table[] = \sprintf('| `%s` | %s | %s |', $change->entity, $change->change, $change->reason);
            }

            // Safe changes need no action, so they stay folded away behind the ones that do
            array_push($lines, '', ...match ($risk) {
                ChangeRisk::Breaking => ['#### 🔴 Breaking', '', "{$risk->describe()}.", '', ...$table],
                ChangeRisk::Caution => ['#### 🟡 Caution', '', "{$risk->describe()}.", '', ...$table],
                ChangeRisk::Safe => [\sprintf('<details><summary>🟢 Safe (%d): nothing to do</summary>', \count($ofRisk)), '', ...$table, '', '</details>'],
            });
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<BaselineChange> $changes
     *
     * @return list<array{ChangeRisk, non-empty-list<BaselineChange>}> - the worst risk first
     */
    private function groupByRisk(array $changes): array
    {
        $groups = [];
        foreach (ChangeRisk::cases() as $risk) {
            $ofRisk = array_values(array_filter($changes, static fn (BaselineChange $change): bool => $change->risk === $risk));
            if ($ofRisk !== []) {
                $groups[] = [$risk, $ofRisk];
            }
        }

        return $groups;
    }

    /**
     * @param list<BaselineChange> $changes
     */
    private function countBreaking(array $changes): int
    {
        return \count(array_filter($changes, static fn (BaselineChange $change): bool => $change->risk === ChangeRisk::Breaking));
    }
}
