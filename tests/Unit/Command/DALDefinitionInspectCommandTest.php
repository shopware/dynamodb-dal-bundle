<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Command;

use Shopware\DynamodbDalBundle\Tests\Unit\Fixtures\CommandDefinitions;
use Shopware\DynamodbDalBundle\Tests\Unit\Fixtures\CustomerEntity;
use Shopware\DynamodbDalBundle\Tests\Unit\Fixtures\OrderEntity;
use Shopware\DynamodbDalBundle\Command\DALDefinitionInspectCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

#[CoversClass(DALDefinitionInspectCommand::class)]
class DALDefinitionInspectCommandTest extends TestCase
{
    private BufferedOutput $output;

    private SymfonyStyle $io;

    private DALDefinitionInspectCommand $command;

    protected function setUp(): void
    {
        $this->output = new BufferedOutput();
        $this->io = new SymfonyStyle(static::createStub(InputInterface::class), $this->output);
        $this->command = new DALDefinitionInspectCommand([
            'order' => CommandDefinitions::order(),
            'config' => CommandDefinitions::customer(),
        ]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function entityIdentifierProvider(): iterable
    {
        yield 'logical name' => ['order'];
        yield 'fully qualified class name' => [OrderEntity::class];
        yield 'fully qualified class name with leading backslash' => ['\\' . OrderEntity::class];
        yield 'short class name' => ['OrderEntity'];
        yield 'lowercased short class name' => ['orderentity'];
    }

    #[DataProvider('entityIdentifierProvider')]
    public function testResolvesEntityByIdentifier(string $identifier): void
    {
        static::assertSame(Command::SUCCESS, $this->command->__invoke($this->io, $identifier));
        static::assertStringContainsString(OrderEntity::class, $this->output->fetch());
    }

    public function testRendersTableKeySchemaIndexesAndFields(): void
    {
        static::assertSame(Command::SUCCESS, $this->command->__invoke($this->io, 'order'));

        $output = $this->output->fetch();

        static::assertStringContainsString('phpunit-order', $output);
        static::assertStringContainsString('referenceIndex', $output);
        static::assertStringContainsString('tenantId', $output);
        static::assertStringContainsString('HASH', $output);
        static::assertStringContainsString('RANGE', $output);
        static::assertStringContainsString('StringFieldSerializer', $output);
        static::assertStringContainsString('ListFieldSerializer', $output);
        static::assertStringContainsString('list<string>', $output);
    }

    public function testRendersTheTypeEachFieldIsStoredAs(): void
    {
        static::assertSame(Command::SUCCESS, $this->command->__invoke($this->io, 'order'));

        static::assertMatchesRegularExpression('/stored as.*\n(?:.*\n)*.*StringFieldSerializer\s+S\s/', $this->output->fetch());
    }

    /**
     * The compiled value definitions of an `array<string, list<string>>` field, so both nesting levels
     * have to be named after the type each is stored as.
     */
    public function testRendersNestedMapAndListValueDefinitions(): void
    {
        static::assertSame(Command::SUCCESS, $this->command->__invoke($this->io, 'order'));
        static::assertStringContainsString('map<list<string>>', $this->output->fetch());
    }

    public function testRendersEntityWithoutIndexesAndNormalizer(): void
    {
        static::assertSame(Command::SUCCESS, $this->command->__invoke($this->io, 'config'));

        $output = $this->output->fetch();

        static::assertStringContainsString(CustomerEntity::class, $output);
        static::assertStringContainsString('none', $output);
    }

    public function testFailsForUnknownEntityAndListsAvailableOnes(): void
    {
        static::assertSame(Command::FAILURE, $this->command->__invoke($this->io, 'unknown'));

        $output = $this->output->fetch();

        static::assertStringContainsString('No DAL definition found for "unknown".', $output);
        static::assertStringContainsString(CustomerEntity::class, $output);
        static::assertStringContainsString(OrderEntity::class, $output);
    }

    public function testSuggestsEveryEntityClassDescribedByItsTableName(): void
    {
        $suggestions = [];
        foreach ($this->command->suggestEntities() as $suggestion) {
            $suggestions[$suggestion->getValue()] = $suggestion->getDescription();
        }

        static::assertSame([
            CustomerEntity::class => 'config',
            OrderEntity::class => 'order',
        ], $suggestions);
    }

    public function testFailsWithoutEntityWhenNotInteractive(): void
    {
        static::assertSame(Command::FAILURE, $this->command->__invoke($this->io));
        static::assertStringContainsString('No entity given.', $this->output->fetch());
    }

    public function testSelectEntityFillsArgumentWithChosenClass(): void
    {
        $input = $this->createMock(InputInterface::class);
        $input->expects(static::once())->method('getArgument')->with('entity')->willReturn(null);
        $input->expects(static::once())
            ->method('setArgument')
            ->with('entity', OrderEntity::class);

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects(static::once())
            ->method('choice')
            ->with('Select an entity', [CustomerEntity::class, OrderEntity::class])
            ->willReturn(OrderEntity::class);

        $this->command->selectEntity($input, $io);
    }

    public function testSelectEntityKeepsGivenArgument(): void
    {
        $input = $this->createMock(InputInterface::class);
        $input->expects(static::once())->method('getArgument')->with('entity')->willReturn('order');
        $input->expects(static::never())->method('setArgument');

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects(static::never())->method('choice');

        $this->command->selectEntity($input, $io);
    }
}
