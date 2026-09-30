<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\DynamodbDalBundle\Command\Baseline\Baseline;
use Shopware\DynamodbDalBundle\Command\DALBaselineCompareCommand;
use Shopware\DynamodbDalBundle\Command\DALBaselineDumpCommand;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\DynamoDbTestKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Runs the baseline commands of a `dev` kernel on the compiled fixture entities, and compares them with the baseline
 * CI's baseline action compares pull requests with. Neither command reads a table, so no DynamoDB is needed.
 */
#[CoversClass(DALBaselineDumpCommand::class)]
#[CoversClass(DALBaselineCompareCommand::class)]
#[CoversClass(Baseline::class)]
class BaselineCommandsTest extends TestCase
{
    private const string BASELINE = __DIR__ . '/Fixtures/dal-baseline.json';

    private static ?DynamoDbTestKernel $kernel = null;

    public static function setUpBeforeClass(): void
    {
        self::$kernel = new DynamoDbTestKernel('http://127.0.0.1:8345', 'dev');
        self::$kernel->boot();
    }

    public static function tearDownAfterClass(): void
    {
        self::$kernel?->shutdown();
        self::$kernel = null;
    }

    public function testDumpMatchesTheCommittedBaseline(): void
    {
        $tester = $this->tester('dal:baseline:dump');

        static::assertSame(Command::SUCCESS, $tester->execute([]));
        static::assertSame(
            file_get_contents(self::BASELINE),
            $tester->getDisplay(),
            'The fixture entities changed. Run `bin/console dal:baseline:dump > tests/Integration/Fixtures/dal-baseline.json`',
        );
    }

    public function testNothingChangedSinceTheCommittedBaseline(): void
    {
        $tester = $this->tester('dal:baseline:compare');

        static::assertSame(Command::SUCCESS, $tester->execute(['baseline' => self::BASELINE]));
        static::assertStringContainsString('Nothing changed since the baseline.', $tester->getDisplay());

        static::assertSame(Command::SUCCESS, $tester->execute(['baseline' => self::BASELINE, '--format' => 'markdown']));
        static::assertSame('', $tester->getDisplay());
    }

    /**
     * What the action pipes in: an earlier baseline on standard input, here one before `name` became optional and
     * `counter` was stored as a number.
     */
    public function testReportsTheChangesOfTheCompiledEntitiesSinceABaselineOnStandardInput(): void
    {
        $earlier = json_decode((string) file_get_contents(self::BASELINE), true, flags: \JSON_THROW_ON_ERROR);
        static::assertIsArray($earlier);
        static::assertIsArray($earlier['record']);
        static::assertIsArray($earlier['record']['fields']);
        $earlier['record']['fields']['name'] = ['type' => 'S', 'required' => true];
        $earlier['record']['fields']['counter'] = ['type' => 'S', 'required' => false];

        $tester = $this->tester('dal:baseline:compare');
        $tester->setInputs([json_encode($earlier, \JSON_THROW_ON_ERROR)]);

        static::assertSame(Command::SUCCESS, $tester->execute(['baseline' => '-', '--format' => 'markdown']));

        $display = $tester->getDisplay();
        static::assertStringContainsString('**1 of 2 changes may break stored rows or requests.**', $display);
        static::assertStringContainsString('| `record` | field `counter` stored as `S` → `N` |', $display);
        static::assertStringContainsString('| `record` | field `name` is no longer required |', $display);
    }

    private function tester(string $command): CommandTester
    {
        $kernel = self::$kernel;
        static::assertNotNull($kernel);

        return new CommandTester(new Application($kernel)->find($command));
    }
}
