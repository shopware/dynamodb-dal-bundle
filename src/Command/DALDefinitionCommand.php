<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Command;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\FieldDefinition;
use Shopware\DynamodbDalBundle\Serializer\Field\ListFieldSerializer;
use Shopware\DynamodbDalBundle\Serializer\Field\MapFieldSerializer;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Interact;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Completion\Suggestion;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * @internal
 */
#[AsCommand(
    name: 'dal:definition',
    description: 'Print the compiled DAL definition of an entity',
    help: <<<'HELP'
            The <info>%command.name%</info> command prints the definition the
            <info>Shopware\DynamodbDalBundle\DefinitionCompilerPass</info> compiled for an entity: its table and
            key schema, its global secondary indexes, and every <info>#[Field]</info> property with the
            type, nullability, default value and serializer it was compiled with.

            The entity can be given as a fully qualified class name, as its short class name, or as the
            logical table name from <info>#[Table(name: ..)]</info>. Run without an argument to select from
            every registered entity; that prompt needs an interactive terminal, so a non-interactive run
            has to pass one.

            Examples:

              Select an entity interactively:
                <info>%command.full_name%</info>

              Dump one by class, short name or table name:
                <info>%command.full_name% 'App\Entity\OrderEntity'</info>
                <info>%command.full_name% OrderEntity</info>
                <info>%command.full_name% order</info>
            HELP
)]
readonly class DALDefinitionCommand
{
    /**
     * @param iterable<string, EntityDefinition<AbstractEntity>> $entityDefinitions
     */
    public function __construct(
        private iterable $entityDefinitions,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(
            description: 'Entity class (fully qualified or short), or the logical table name; omit to select one interactively',
            suggestedValues: [self::class, 'suggestEntities'],
        )]
        ?string $entity = null,
    ): int {
        $definitions = $this->getDefinitionsByClass();

        if ($entity === null) {
            $io->error('No entity given. Pass one as argument or run the command interactively to select one.');
            $this->renderAvailableEntities($io, $definitions);

            return Command::FAILURE;
        }

        $definition = $this->resolveDefinition($definitions, $entity);
        if ($definition === null) {
            $io->error(\sprintf('No DAL definition found for "%s".', $entity));
            $this->renderAvailableEntities($io, $definitions);

            return Command::FAILURE;
        }

        $this->renderDefinition($io, $definition);

        return Command::SUCCESS;
    }

    /**
     * Completes the argument with every entity class, described by the table name it also accepts.
     * Referenced as a static callable from the attribute, which {@see Argument} rebinds to this instance.
     *
     * @return list<Suggestion>
     */
    public function suggestEntities(): array
    {
        $suggestions = [];
        foreach ($this->getDefinitionsByClass() as $class => $definition) {
            $suggestions[] = new Suggestion($class, $definition->getName());
        }

        return $suggestions;
    }

    /**
     * Fills the entity argument from a selection of all registered entity classes. Runs on
     * interactive input only, so a missing argument stays an error in scripts and CI.
     */
    #[Interact]
    public function selectEntity(InputInterface $input, SymfonyStyle $io): void
    {
        if ($input->getArgument('entity') !== null) {
            return;
        }

        $classes = array_keys($this->getDefinitionsByClass());

        $input->setArgument('entity', $io->choice('Select an entity', $classes));
    }

    /**
     * @param array<class-string<AbstractEntity>, EntityDefinition<AbstractEntity>> $definitions
     */
    private function renderAvailableEntities(SymfonyStyle $io, array $definitions): void
    {
        $rows = [];
        foreach ($definitions as $class => $definition) {
            $rows[] = [$class, $definition->getName()];
        }

        $io->table(['entity', 'name'], $rows);
    }

    /**
     * @return array<class-string<AbstractEntity>, EntityDefinition<AbstractEntity>> - keyed by entity class, sorted by class name
     */
    private function getDefinitionsByClass(): array
    {
        $byClass = [];
        foreach ($this->entityDefinitions as $definition) {
            if (!$definition instanceof EntityDefinition) {
                continue;
            }

            $byClass[$definition->getClass()] = $definition;
        }

        ksort($byClass);

        return $byClass;
    }

    /**
     * @param array<class-string<AbstractEntity>, EntityDefinition<AbstractEntity>> $definitions
     *
     * @return EntityDefinition<AbstractEntity>|null
     */
    private function resolveDefinition(array $definitions, string $entity): ?EntityDefinition
    {
        $needle = strtolower(ltrim($entity, '\\'));

        foreach ($definitions as $class => $definition) {
            $candidates = [
                strtolower($class),
                strtolower($this->getShortClassName($class)),
                strtolower($definition->getName()),
            ];

            if (\in_array($needle, $candidates, true)) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * @param EntityDefinition<AbstractEntity> $definition
     */
    private function renderDefinition(SymfonyStyle $io, EntityDefinition $definition): void
    {
        $keySchema = $definition->getKeySchema();
        $normalizer = $definition->getNormalizer();

        $io->title($definition->getClass());
        $io->definitionList(
            ['name' => $definition->getName()],
            ['table' => $definition->getTable()],
            ['normalizer' => $normalizer === null ? '-' : $normalizer::class],
            ['hash key' => $keySchema->hashKey],
            ['range key' => $keySchema->rangeKey ?? '-'],
        );

        $this->renderIndexes($io, $definition);
        $this->renderFields($io, $definition);
    }

    /**
     * @param EntityDefinition<AbstractEntity> $definition
     */
    private function renderIndexes(SymfonyStyle $io, EntityDefinition $definition): void
    {
        $io->section('Global secondary indexes');

        $indexes = $definition->getIndexes();
        if ($indexes === []) {
            $io->writeln('none');
            $io->newLine();

            return;
        }

        $rows = [];
        foreach ($indexes as $index) {
            $rows[] = [$index->name, $index->keySchema->hashKey, $index->keySchema->rangeKey ?? '-'];
        }

        $io->table(['index', 'hash key', 'range key'], $rows);
    }

    /**
     * @param EntityDefinition<AbstractEntity> $definition
     */
    private function renderFields(SymfonyStyle $io, EntityDefinition $definition): void
    {
        $io->section('Fields');

        $keyRoles = $this->getKeyRoles($definition);

        $rows = [];
        foreach ($definition->getFieldDefinitions() as $fieldName => $field) {
            $rows[] = [
                $fieldName,
                $this->describeType($field),
                $field->allowsNull() ? 'yes' : 'no',
                $field->hasDefaultValue() ? $this->describeValue($field->getDefaultValue()) : '-',
                implode(', ', $keyRoles[$fieldName] ?? []) ?: '-',
                $this->getShortClassName($field->getSerializer()::class),
            ];
        }

        $io->table(['field', 'type', 'nullable', 'default', 'key', 'serializer'], $rows);
    }

    /**
     * @param EntityDefinition<AbstractEntity> $definition
     *
     * @return array<string, list<string>> - key roles per field name, e.g. `HASH` or `<index> RANGE`
     */
    private function getKeyRoles(EntityDefinition $definition): array
    {
        $keySchema = $definition->getKeySchema();

        $roles = [$keySchema->hashKey => ['HASH']];
        if ($keySchema->rangeKey !== null) {
            $roles[$keySchema->rangeKey][] = 'RANGE';
        }

        foreach ($definition->getIndexes() as $index) {
            $roles[$index->keySchema->hashKey][] = "{$index->name} HASH";
            if ($index->keySchema->rangeKey !== null) {
                $roles[$index->keySchema->rangeKey][] = "{$index->name} RANGE";
            }
        }

        return $roles;
    }

    /**
     * Renders the compiled type of a field, recursing into the value definitions of map and list
     * fields. A {@see FieldDefinition} keeps only the PHP type — `array` for both a map and a list —
     * so the container is named after the serializer the compiler picked for that level, which is the
     * only place the `@var` shape survives.
     */
    private function describeType(FieldDefinition $field): string
    {
        // Value types are compiled verbatim from the property's @var docblock, where a class is commonly written with a leading backslash.
        $type = ltrim($field->getType(), '\\');
        $valueField = $field->getValueFieldDefinition();

        if ($valueField === null) {
            return $type;
        }

        $serializer = $field->getSerializer();
        $container = match (true) {
            $serializer instanceof ListFieldSerializer => 'list',
            $serializer instanceof MapFieldSerializer => 'map',
            default => $type,
        };

        return \sprintf('%s<%s>', $container, $this->describeType($valueField));
    }

    private function describeValue(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            \is_bool($value) => $value ? 'true' : 'false',
            \is_string($value) => "'{$value}'",
            \is_int($value), \is_float($value) => (string) $value,
            $value instanceof \UnitEnum => $this->getShortClassName($value::class) . "::{$value->name}",
            \is_array($value) => json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR),
            default => get_debug_type($value),
        };
    }

    private function getShortClassName(string $class): string
    {
        $separator = strrpos($class, '\\');

        return $separator === false ? $class : substr($class, $separator + 1);
    }
}
