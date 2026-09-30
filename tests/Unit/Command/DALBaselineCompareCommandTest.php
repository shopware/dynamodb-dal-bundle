<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Command;

use Shopware\DynamodbDalBundle\Command\Baseline\Baseline;
use Shopware\DynamodbDalBundle\Command\DALBaselineCompareCommand;
use Shopware\DynamodbDalBundle\Tests\Unit\Fixtures\CommandDefinitions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

#[CoversClass(DALBaselineCompareCommand::class)]
class DALBaselineCompareCommandTest extends TestCase
{
    private ArrayInput $input;

    private BufferedOutput $output;

    private DALBaselineCompareCommand $command;

    /**
     * @var list<string>
     */
    private array $files = [];

    protected function setUp(): void
    {
        $this->input = new ArrayInput([]);
        $this->output = new BufferedOutput();
        $this->command = new DALBaselineCompareCommand([
            'order' => CommandDefinitions::order(),
            'config' => CommandDefinitions::customer(),
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            chmod($file, 0o600);
            unlink($file);
        }
    }

    public function testSaysSoWhereNothingChanged(): void
    {
        static::assertSame(Command::SUCCESS, $this->compare($this->write(self::currentJson())));
        static::assertStringContainsString('Nothing changed since the baseline.', $this->output->fetch());
    }

    public function testListsEveryChangeByRiskWithWhyItMatters(): void
    {
        static::assertSame(Command::SUCCESS, $this->compare($this->write(self::earlierJson())));

        $output = $this->output->fetch();
        static::assertMatchesRegularExpression('/Breaking: stored rows.*\n-+\n\n \* order: field `revision` stored as `S` → `N`\n   Reading a row that holds it as `S` fails/', $output);
        static::assertMatchesRegularExpression('/Caution: needs a change.*\n-+\n\n \* order: index `revisionIndex` added\n/', $output);
        static::assertMatchesRegularExpression('/Safe: nothing to do\n-+\n\n \* order: field `note` is no longer required\n/', $output);
        static::assertStringContainsString('1 of 3 changes may break stored rows or requests.', $output);
    }

    public function testRendersThePullRequestCommentInMarkdown(): void
    {
        static::assertSame(Command::SUCCESS, $this->compare($this->write(self::earlierJson()), 'markdown'));

        $expected = <<<'MARKDOWN'
            ### DAL baseline changes

            The definitions change what stored rows depend on. **1 of 3 changes may break stored rows or requests.**

            #### 🔴 Breaking

            Stored rows or requests fail once this is deployed, unless it is handled.

            | Entity | Change | Why |
            |---|---|---|
            | `order` | field `revision` stored as `S` → `N` | Reading a row that holds it as `S` fails with `MissingAttributeValueException`, and filters and conditions on it no longer match such a row, until a put writes it again. |

            #### 🟡 Caution

            Needs a change to a table before it is deployed, or drops stored data.

            | Entity | Change | Why |
            |---|---|---|
            | `order` | index `revisionIndex` added | The table needs the index before this is deployed, or every search of it fails. A row that lacks one of its key attributes is not in it. |

            <details><summary>🟢 Safe (1): nothing to do</summary>

            | Entity | Change | Why |
            |---|---|---|
            | `order` | field `note` is no longer required | Every stored row reads as before. |

            </details>

            MARKDOWN;

        static::assertSame($expected, $this->output->fetch());
    }

    public function testPrintsNoMarkdownWhereNothingChanged(): void
    {
        static::assertSame(Command::SUCCESS, $this->compare($this->write(self::currentJson()), 'markdown'));
        static::assertSame('', $this->output->fetch());
    }

    public function testSaysSoInTheCommentWhereNoChangeIsBreaking(): void
    {
        static::assertSame(Command::SUCCESS, $this->compare($this->write(self::earlierJson(breaking: false)), 'markdown'));

        $output = $this->output->fetch();
        static::assertStringStartsWith("### DAL baseline changes\n\nThe definitions change what stored rows depend on, but nothing that breaks them.\n\n#### 🟡 Caution\n", $output);
        static::assertStringNotContainsString('Breaking', $output);
        static::assertStringContainsString('<details><summary>🟢 Safe (1): nothing to do</summary>', $output);
    }

    public function testWarnsOfNoBreakingChangeWhereThereIsNone(): void
    {
        static::assertSame(Command::SUCCESS, $this->compare($this->write(self::earlierJson(breaking: false))));

        $output = $this->output->fetch();
        static::assertStringNotContainsString('Breaking', $output);
        static::assertStringNotContainsString('may break', $output);
        static::assertStringContainsString('order: index `revisionIndex` added', $output);
    }

    public function testReadsTheBaselineFromStandardInput(): void
    {
        $stream = fopen('php://memory', 'r+');
        static::assertIsResource($stream);
        fwrite($stream, self::earlierJson());
        rewind($stream);
        $this->input->setStream($stream);

        static::assertSame(Command::SUCCESS, $this->compare('-'));
        static::assertStringContainsString('field `revision` stored as `S` → `N`', $this->output->fetch());
    }

    public function testFailsForABaselineThatCannotBeRead(): void
    {
        static::assertSame(Command::FAILURE, $this->compare('/does/not/exist.json'));
        static::assertStringContainsString('The baseline "/does/not/exist.json" cannot be read.', $this->output->fetch());
    }

    public function testFailsForABaselineThatIsADirectory(): void
    {
        static::assertSame(Command::FAILURE, $this->compare(sys_get_temp_dir()));
        static::assertStringContainsString('cannot be read.', $this->output->fetch());
    }

    public function testFailsForABaselineThatIsNotReadable(): void
    {
        $file = $this->write(self::currentJson());
        chmod($file, 0o000);
        clearstatcache();

        if (is_readable($file)) {
            static::markTestSkipped('Runs as a user who reads every file, such as root');
        }

        static::assertSame(Command::FAILURE, $this->compare($file));
        static::assertStringContainsString('cannot be read.', $this->output->fetch());
    }

    public function testFailsForABaselineThatIsNoBaseline(): void
    {
        static::assertSame(Command::FAILURE, $this->compare($this->write('[1]')));
        static::assertStringContainsString('The baseline is not an object', $this->output->fetch());
    }

    public function testRefusesAnUnknownFormat(): void
    {
        static::assertSame(Command::INVALID, $this->compare($this->write(self::currentJson()), 'html'));
        static::assertStringContainsString('The format "html" is not one of text, markdown.', $this->output->fetch());
    }

    private function compare(string $baseline, string $format = 'text'): int
    {
        return $this->command->__invoke($this->input, $this->output, new SymfonyStyle($this->input, $this->output), $baseline, $format);
    }

    private function write(string $json): string
    {
        $file = tempnam(sys_get_temp_dir(), 'dal-baseline');
        static::assertIsString($file);
        file_put_contents($file, $json);
        $this->files[] = $file;

        return $file;
    }

    private static function currentJson(): string
    {
        return Baseline::fromDefinitions([CommandDefinitions::order(), CommandDefinitions::customer()])->toJson();
    }

    /**
     * The current baseline before `revisionIndex` was added and `note` became optional, and, where it is to hold a
     * breaking change, before `revision` was stored as a number.
     */
    private static function earlierJson(bool $breaking = true): string
    {
        $baseline = json_decode(self::currentJson(), true, flags: \JSON_THROW_ON_ERROR);
        static::assertIsArray($baseline);
        static::assertIsArray($baseline['order']);
        static::assertIsArray($baseline['order']['fields']);
        static::assertIsArray($baseline['order']['indexes']);

        if ($breaking) {
            $baseline['order']['fields']['revision'] = ['type' => 'S', 'required' => false];
        }

        $baseline['order']['fields']['note'] = ['type' => 'S', 'required' => true];
        unset($baseline['order']['indexes']['revisionIndex']);

        return json_encode($baseline, \JSON_THROW_ON_ERROR);
    }
}
