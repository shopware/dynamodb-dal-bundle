<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Expression;

use Shopware\DynamodbDalBundle\Exception\UpdateDuplicatePathException;
use Shopware\DynamodbDalBundle\Exception\UpdateEmptyException;
use Shopware\DynamodbDalBundle\Exception\WrongTypeException;
use Shopware\DynamodbDalBundle\Expression\Filter;
use Shopware\DynamodbDalBundle\Expression\FilterCompiler;
use Shopware\DynamodbDalBundle\Expression\Update;
use Shopware\DynamodbDalBundle\Expression\Update\AddAction;
use Shopware\DynamodbDalBundle\Expression\Update\ListAppendAction;
use Shopware\DynamodbDalBundle\Expression\Update\SetIfNotExistsAction;
use Shopware\DynamodbDalBundle\Expression\Update\UpdateExpression;
use Shopware\DynamodbDalBundle\Expression\UpdateCompiler;
use Shopware\DynamodbDalBundle\Serializer\NormalizerContext;
use Shopware\DynamodbDalBundle\Serializer\NormalizerOperation;
use Shopware\DynamodbDalBundle\Serializer\Serializer;
use Shopware\DynamodbDalBundle\Tests\Unit\Expression\Fixtures\CounterDefinition;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\RecordingNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(UpdateCompiler::class)]
class UpdateCompilerTest extends TestCase
{
    private UpdateCompiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new UpdateCompiler(new Serializer());
    }

    /**
     * An update and its condition go out in one request, so their value placeholders have to stay apart, although
     * two compilers number them.
     */
    public function testAnUpdateAndItsConditionKeepTheirValuesApart(): void
    {
        $definition = CounterDefinition::create();
        [, $update] = $this->compiler->update($definition, Update::set('name', 'b'));
        $condition = new FilterCompiler()->condition($definition, Filter::equals('name', 'a'));

        static::assertSame([], array_intersect_key($update->values, $condition->values));
        static::assertCount(2, $update->getExpressionAttributes($condition)['ExpressionAttributeValues'] ?? []);
    }

    /**
     * One call, so a normalizer that acts on several keys sees the value an action selects beside the fields, and
     * never the action. Each value goes back where it came from, and a field the normalizer adds becomes a field.
     */
    public function testAnUpdateIsNormalizedInOneCallWithTheValuesItsActionsSelect(): void
    {
        $normalizer = new RecordingNormalizer(static function (NormalizerContext $context): void {
            $context->set('name', 'normalized a');
            $context->set('ratio', 2.5);
            $context->set('tags', ['X']);
            $context->set('meta.added', 1);
        });
        $update = Update::with(Update::set('name', 'a'), Update::setIfNotExists('ratio', 1.5), Update::append('tags', ['x']), Update::increment('count'));

        [$normalized] = $this->compiler->update(CounterDefinition::create($normalizer), $update);

        static::assertSame([['normalize', NormalizerOperation::Update, ['name' => 'a', 'ratio' => 1.5, 'tags' => ['x']]]], $normalizer->calls);
        static::assertSame(['name' => 'normalized a', 'meta.added' => 1], $normalized->fields);
        static::assertEquals(
            [new SetIfNotExistsAction('ratio', 2.5), new ListAppendAction('tags', ['X']), new AddAction('count', 1)],
            $normalized->actions,
        );
        static::assertEquals(
            Update::with(Update::set('name', 'a'), Update::setIfNotExists('ratio', 1.5), Update::append('tags', ['x']), Update::increment('count')),
            $update,
        );
    }

    /**
     * The normalizer's map holds a path once, so one of two values would win without a word. Where the winner is
     * `null`, the other would even write nothing, and DynamoDB would never see the overlap.
     */
    #[DataProvider('pathsGivenTwoValuesProvider')]
    public function testAPathGivenTwoValuesIsRefused(UpdateExpression $update): void
    {
        $this->expectException(UpdateDuplicatePathException::class);
        $this->expectExceptionMessage('Update of item "counter" writes path "name" more than once');

        $this->compiler->update(CounterDefinition::create(), $update);
    }

    /**
     * @return iterable<string, array{UpdateExpression}>
     */
    public static function pathsGivenTwoValuesProvider(): iterable
    {
        yield 'a field and the value of an action' => [Update::with(Update::set('name', 'a'), Update::setIfNotExists('name', 'b'))];
        yield 'a field and an action without a value' => [Update::with(Update::set('name', 'a'), Update::setIfNotExists('name', null))];
        yield 'an action without a value and a field' => [Update::with(Update::setIfNotExists('name', null), Update::set('name', 'a'))];
        yield 'a removed field and the value of an action' => [Update::with(Update::remove('name'), Update::setIfNotExists('name', 'a'))];
        yield 'the values of two actions' => [Update::with(Update::setIfNotExists('name', 'a'), Update::setIfNotExists('name', null), Update::set('count', 1))];
    }

    public function testAnAppendWithoutElementsHasNothingToWrite(): void
    {
        $this->expectException(UpdateEmptyException::class);

        $this->compiler->update(CounterDefinition::create(), Update::append('tags', []));
    }

    public function testAnAppendTheNormalizerLeavesWithoutAListIsRefusedForItsField(): void
    {
        $normalizer = new RecordingNormalizer(static fn (NormalizerContext $context) => $context->set('tags', 'x'));

        try {
            $this->compiler->update(CounterDefinition::create($normalizer), Update::append('tags', ['x']));
            static::fail('The append should have been refused.');
        } catch (WrongTypeException $exception) {
            static::assertSame('tags', $exception->fieldDefinition->getName());
            static::assertSame('string', $exception->actualType);
        }
    }

    public function testAnActionTheNormalizerLeavesWithoutAValueWritesNothing(): void
    {
        $normalizer = new RecordingNormalizer(static function (NormalizerContext $context): void {
            $context->remove('name');
            $context->remove('tags');
        });

        [, $compiled] = $this->compiler->update(
            CounterDefinition::create($normalizer),
            Update::with(Update::setIfNotExists('name', 'blank'), Update::append('tags', ['x']), Update::set('count', 1)),
        );

        static::assertSame('SET #count = :u_1_0_count', $compiled->expression);
        static::assertSame(['#count' => 'count'], $compiled->names);
    }

    /**
     * Leaving a path out means the update does not touch it, so the action writing it goes too, while one that
     * selects no value is kept.
     */
    public function testAnActionWhosePathTheNormalizerLeavesOutIsDropped(): void
    {
        $normalizer = new RecordingNormalizer(static function (NormalizerContext $context): void {
            $context->omit('name');
            $context->omit('tags');
        });

        [$normalized, $compiled] = $this->compiler->update(
            CounterDefinition::create($normalizer),
            Update::with(Update::setIfNotExists('name', 'a'), Update::append('tags', ['x']), Update::increment('count')),
        );

        static::assertSame([], $normalized->fields);
        static::assertEquals([new AddAction('count', 1)], $normalized->actions);
        static::assertSame('ADD #count :u_1_0_count', $compiled->expression);
    }

    /**
     * A field set to `null` is removed, but one the normalizer leaves out is not written at all.
     */
    public function testAFieldTheNormalizerLeavesOutIsNotWritten(): void
    {
        $normalizer = new RecordingNormalizer(static fn (NormalizerContext $context) => $context->omit('name'));

        [$normalized] = $this->compiler->update(CounterDefinition::create($normalizer), Update::setFields(['name' => 'a', 'count' => 1]));

        static::assertSame(['count' => 1], $normalized->fields);
    }

    /**
     * DynamoDB rejects an empty update expression, so the update fails before a request is built.
     */
    public function testAnUpdateWithNothingToWriteIsRefused(): void
    {
        $this->expectException(UpdateEmptyException::class);

        $this->compiler->update(CounterDefinition::create(), Update::setFields([]));
    }
}
