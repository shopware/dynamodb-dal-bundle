<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Integration\DynamoDb;

use PHPUnit\Framework\Attributes\CoversClass;
use Shopware\DynamodbDalBundle\Command\DALDefinitionValidateCommand;
use Shopware\DynamodbDalBundle\Test\EntityDefinitionFactory;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\DynamoDbTestKernel;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\Entity\ArchiveEntity;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\Entity\RecordEntity;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\Entity\StringSetFieldSerializer;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Compares definitions with what DynamoDB Local describes of the fixture tables, in the `dev` kernel the command
 * is registered in.
 */
#[CoversClass(DALDefinitionValidateCommand::class)]
class DefinitionValidateCommandTest extends DynamoDbTestCase
{
    public function testEveryFixtureTableMatchesItsDefinition(): void
    {
        $tester = new CommandTester(new Application($this->kernel())->find('dal:definition:validate'));

        static::assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());
        static::assertStringContainsString(\sprintf('%d tables match their definitions.', \count(DynamoDbTestKernel::TABLES)), $tester->getDisplay());
    }

    public function testReportsATableThatDiffersFromItsDefinitionAndOneThatDoesNotExist(): void
    {
        $command = new DALDefinitionValidateCommand([
            // A composite key and an index, compared with a table that has a hash key alone
            'record' => EntityDefinitionFactory::create(RecordEntity::class, table: DynamoDbTestKernel::TABLES['archive']),
            'archive' => EntityDefinitionFactory::create(ArchiveEntity::class, [new StringSetFieldSerializer()], table: 'phpunit-missing'),
        ], $this->dynamo());

        $output = new BufferedOutput();
        static::assertSame(Command::FAILURE, $command->__invoke(new SymfonyStyle(new ArrayInput([]), $output)));

        $display = $output->fetch();
        static::assertStringContainsString('error: the table does not exist', $display);
        static::assertStringContainsString('error: the table has hash key "id" instead of "tenantId"', $display);
        static::assertStringContainsString('error: the table has no range key "id"', $display);
        static::assertStringContainsString('error: index "statusIndex" does not exist', $display);
        static::assertStringContainsString('2 of 2 tables do not match their definitions.', $display);
    }

    protected static function createKernel(string $endpoint): DynamoDbTestKernel
    {
        return new DynamoDbTestKernel($endpoint, 'dev');
    }
}
