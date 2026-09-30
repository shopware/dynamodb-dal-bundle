<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Command;

use Shopware\DynamodbDalBundle\Command\DALDefinitionValidateCommand;
use Shopware\DynamodbDalBundle\Tests\Unit\Fixtures\CommandDefinitions;
use AsyncAws\Core\AwsError\AwsError;
use AsyncAws\Core\Test\Http\SimpleMockedResponse;
use AsyncAws\Core\Test\ResultMockFactory;
use AsyncAws\DynamoDb\DynamoDbClient;
use AsyncAws\DynamoDb\Exception\ResourceNotFoundException;
use AsyncAws\DynamoDb\Result\DescribeTableOutput;
use AsyncAws\DynamoDb\ValueObject\TableDescription;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

#[CoversClass(DALDefinitionValidateCommand::class)]
class DALDefinitionValidateCommandTest extends TestCase
{
    private BufferedOutput $output;

    private SymfonyStyle $io;

    protected function setUp(): void
    {
        $this->output = new BufferedOutput();
        $this->io = new SymfonyStyle(static::createStub(InputInterface::class), $this->output);
    }

    public function testSucceedsWhereEveryTableMatchesItsDefinition(): void
    {
        static::assertSame(Command::SUCCESS, $this->validate(self::orderTable(), self::customerTable()));

        $output = $this->output->fetch();
        static::assertMatchesRegularExpression('/config\s+phpunit-customer\s+ok/', $output);
        static::assertMatchesRegularExpression('/order\s+phpunit-order\s+ok/', $output);
        static::assertStringContainsString('2 tables match their definitions.', $output);
    }

    public function testFailsForATableThatDoesNotExist(): void
    {
        static::assertSame(Command::FAILURE, $this->validate(self::orderTable(), null));

        $output = $this->output->fetch();
        static::assertMatchesRegularExpression('/config\s+phpunit-customer\s+error: the table does not exist/', $output);
        static::assertMatchesRegularExpression('/order\s+phpunit-order\s+ok/', $output);
        static::assertStringContainsString('1 of 2 tables do not match their definitions.', $output);
    }

    /**
     * @return iterable<string, array{TableDescription, TableDescription, string}>
     */
    public static function mismatchProvider(): iterable
    {
        yield 'another hash key' => [
            self::orderTable(keySchema: [self::key('id', 'HASH'), self::key('externalId', 'RANGE')]),
            self::customerTable(),
            'the table has hash key "id" instead of "tenantId"',
        ];

        yield 'no range key' => [
            self::orderTable(keySchema: [self::key('tenantId', 'HASH')]),
            self::customerTable(),
            'the table has no range key "externalId"',
        ];

        yield 'an undeclared range key' => [
            self::orderTable(),
            self::customerTable(keySchema: [self::key('tenantId', 'HASH'), self::key('id', 'RANGE')]),
            'the table has range key "id", which the definition does not declare',
        ];

        yield 'a key of another type' => [
            self::orderTable(attributeDefinitions: [...self::orderAttributeDefinitions(), 'revision' => self::attributeDefinition('revision', 'S')]),
            self::customerTable(),
            'key "revision" is of type S, but its field is stored as N',
        ];

        yield 'a missing index' => [
            self::orderTable(globalIndexes: []),
            self::customerTable(),
            'index "referenceIndex" does not exist',
        ];

        yield 'an index with another key' => [
            self::orderTable(globalIndexes: [self::index('referenceIndex', 'reference', 'note')]),
            self::customerTable(),
            'index "referenceIndex" has range key "note" instead of "revision"',
        ];

        yield 'an index that projects keys only' => [
            self::orderTable(globalIndexes: [self::index('referenceIndex', 'reference', 'revision', ['ProjectionType' => 'KEYS_ONLY'])]),
            self::customerTable(),
            'index "referenceIndex" does not project "note", "tags", "groups", so an entity read from it lacks them',
        ];

        yield 'a local index that projects some fields' => [
            self::orderTable(localIndexes: [self::index('revisionIndex', 'tenantId', 'revision', ['ProjectionType' => 'INCLUDE', 'NonKeyAttributes' => ['note']])]),
            self::customerTable(),
            'index "revisionIndex" does not project "reference", "tags", "groups", so an entity read from it lacks them',
        ];
    }

    #[DataProvider('mismatchProvider')]
    public function testFailsForATableThatDiffersFromItsDefinition(TableDescription $order, TableDescription $customer, string $error): void
    {
        static::assertSame(Command::FAILURE, $this->validate($order, $customer));

        $output = $this->output->fetch();
        static::assertStringContainsString("error: {$error}", $output);
        static::assertStringContainsString('1 of 2 tables do not match their definitions.', $output);
    }

    public function testListsEveryErrorOfATable(): void
    {
        $order = self::orderTable(keySchema: [self::key('id', 'HASH')], globalIndexes: []);

        static::assertSame(Command::FAILURE, $this->validate($order, self::customerTable()));

        $output = $this->output->fetch();
        static::assertStringContainsString('error: the table has hash key "id" instead of "tenantId"', $output);
        static::assertStringContainsString('error: the table has no range key "externalId"', $output);
        static::assertStringContainsString('error: index "referenceIndex" does not exist', $output);
    }

    public function testAcceptsAnIndexThatProjectsEveryField(): void
    {
        $order = self::orderTable(globalIndexes: [
            self::index('referenceIndex', 'reference', 'revision', ['ProjectionType' => 'INCLUDE', 'NonKeyAttributes' => ['note', 'tags', 'groups']]),
        ]);

        static::assertSame(Command::SUCCESS, $this->validate($order, self::customerTable()));
    }

    public function testWarnsOfAnUndeclaredIndexWithoutFailing(): void
    {
        $order = self::orderTable(globalIndexes: [
            self::index('referenceIndex', 'reference', 'revision'),
            self::index('noteIndex', 'note'),
        ]);

        static::assertSame(Command::SUCCESS, $this->validate($order, self::customerTable()));

        $output = $this->output->fetch();
        static::assertStringContainsString('warning: index "noteIndex" is not declared, so it cannot be searched', $output);
        static::assertStringContainsString('2 tables match their definitions.', $output);
    }

    public function testPrintsAttributeNamesThatLookLikeStyleTagsAsTheyAre(): void
    {
        $order = self::orderTable(keySchema: [self::key('<info>', 'HASH'), self::key('externalId', 'RANGE')]);

        static::assertSame(Command::FAILURE, $this->validate($order, self::customerTable()));
        static::assertStringContainsString('the table has hash key "<info>" instead of "tenantId"', $this->output->fetch());
    }

    /**
     * Describes the order and customer tables as given, `null` for one that does not exist.
     */
    private function validate(?TableDescription $order, ?TableDescription $customer): int
    {
        $tables = ['phpunit-order' => $order, 'phpunit-customer' => $customer];

        $client = static::createStub(DynamoDbClient::class);
        $client->method('describeTable')->willReturnCallback(static function (array $input) use ($tables): DescribeTableOutput {
            $table = $tables[$input['TableName']] ?? throw new ResourceNotFoundException(
                new SimpleMockedResponse('{}', ['content-type' => 'application/x-amz-json-1.0'], 400),
                new AwsError('ResourceNotFoundException', 'Requested resource not found', 'Client', null),
            );

            return ResultMockFactory::create(DescribeTableOutput::class, ['Table' => $table]);
        });

        $command = new DALDefinitionValidateCommand([
            'order' => CommandDefinitions::order(),
            'config' => CommandDefinitions::customer(),
        ], $client);

        return $command->__invoke($this->io);
    }

    /**
     * The table {@see CommandDefinitions::order()} maps, with a global and a local index that project everything,
     * unless given otherwise.
     *
     * @param array<array<string, string>>|null $attributeDefinitions
     * @param list<array<string, string>>|null $keySchema
     * @param list<array<string, mixed>>|null $globalIndexes
     * @param list<array<string, mixed>>|null $localIndexes
     */
    private static function orderTable(
        ?array $attributeDefinitions = null,
        ?array $keySchema = null,
        ?array $globalIndexes = null,
        ?array $localIndexes = null,
    ): TableDescription {
        return new TableDescription([
            'TableName' => 'phpunit-order',
            'AttributeDefinitions' => $attributeDefinitions ?? self::orderAttributeDefinitions(),
            'KeySchema' => $keySchema ?? [self::key('tenantId', 'HASH'), self::key('externalId', 'RANGE')],
            'GlobalSecondaryIndexes' => $globalIndexes ?? [self::index('referenceIndex', 'reference', 'revision')],
            'LocalSecondaryIndexes' => $localIndexes ?? [self::index('revisionIndex', 'tenantId', 'revision')],
        ]);
    }

    /**
     * The table {@see CommandDefinitions::customer()} maps, unless given another key schema.
     *
     * @param list<array<string, string>>|null $keySchema
     */
    private static function customerTable(?array $keySchema = null): TableDescription
    {
        return new TableDescription([
            'TableName' => 'phpunit-customer',
            'AttributeDefinitions' => [self::attributeDefinition('tenantId', 'S')],
            'KeySchema' => $keySchema ?? [self::key('tenantId', 'HASH')],
        ]);
    }

    /**
     * @return array<string, array<string, string>> - keyed by attribute name, so that a test can replace one
     */
    private static function orderAttributeDefinitions(): array
    {
        return [
            'tenantId' => self::attributeDefinition('tenantId', 'S'),
            'externalId' => self::attributeDefinition('externalId', 'S'),
            'reference' => self::attributeDefinition('reference', 'S'),
            'revision' => self::attributeDefinition('revision', 'N'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function attributeDefinition(string $name, string $type): array
    {
        return ['AttributeName' => $name, 'AttributeType' => $type];
    }

    /**
     * @return array<string, string>
     */
    private static function key(string $name, string $type): array
    {
        return ['AttributeName' => $name, 'KeyType' => $type];
    }

    /**
     * @param array<string, mixed> $projection
     *
     * @return array<string, mixed>
     */
    private static function index(string $name, string $hashKey, ?string $rangeKey = null, array $projection = ['ProjectionType' => 'ALL']): array
    {
        return [
            'IndexName' => $name,
            'KeySchema' => $rangeKey === null ? [self::key($hashKey, 'HASH')] : [self::key($hashKey, 'HASH'), self::key($rangeKey, 'RANGE')],
            'Projection' => $projection,
        ];
    }
}
