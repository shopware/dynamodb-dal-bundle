<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Expression;

use Shopware\DynamodbDalBundle\Exception\FieldMissingSerializedValueException;
use Shopware\DynamodbDalBundle\Exception\NullOperandException;
use Shopware\DynamodbDalBundle\Exception\UnknownFieldException;
use Shopware\DynamodbDalBundle\Exception\WrongTypeException;
use Shopware\DynamodbDalBundle\Expression\Contract\UpdateActionInterface;
use Shopware\DynamodbDalBundle\Expression\ExpressionCompileContext;
use Shopware\DynamodbDalBundle\Expression\Update;
use Shopware\DynamodbDalBundle\Expression\Update\AddAction;
use Shopware\DynamodbDalBundle\Expression\Update\DeleteAction;
use Shopware\DynamodbDalBundle\Expression\Update\ListAppendAction;
use Shopware\DynamodbDalBundle\Expression\Update\SetIfNotExistsAction;
use Shopware\DynamodbDalBundle\Expression\Update\UpdateClause;
use Shopware\DynamodbDalBundle\Expression\Update\UpdateExpression;
use Shopware\DynamodbDalBundle\Tests\Unit\Expression\Fixtures\CounterDefinition;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(UpdateExpression::class)]
#[CoversClass(AddAction::class)]
#[CoversClass(DeleteAction::class)]
#[CoversClass(ListAppendAction::class)]
#[CoversClass(SetIfNotExistsAction::class)]
class UpdateExpressionTest extends TestCase
{
    private const string PREFIX = 'h';

    public function testFieldsShareOneSetClauseInTheOrderGiven(): void
    {
        [$expression, $context] = $this->compile(new UpdateExpression(['name' => 'a name', 'count' => 7]));

        static::assertSame('SET #name = :h_0_name, #count = :h_1_count', $expression);
        static::assertSame(['#name' => 'name', '#count' => 'count'], $context->names);
        static::assertEquals([
            ':h_0_name' => new AttributeValue(['S' => 'a name']),
            ':h_1_count' => new AttributeValue(['N' => '7']),
        ], $context->values);
    }

    public function testAFieldGivenAsNullIsRemoved(): void
    {
        [$expression, $context] = $this->compile(new UpdateExpression(['name' => null]));

        static::assertSame('REMOVE #name', $expression);
        // The name is still registered — the REMOVE clause has to spell the attribute too.
        static::assertSame(['#name' => 'name'], $context->names);
        static::assertSame([], $context->values);
    }

    public function testSetAndRemoveShareOneExpression(): void
    {
        [$expression] = $this->compile(new UpdateExpression(['name' => null, 'count' => 7]));

        static::assertSame('SET #count = :h_0_count REMOVE #name', $expression);
    }

    public function testAPathAddressesOneMapEntryToSetOrRemove(): void
    {
        [$expression, $context] = $this->compile(new UpdateExpression(['meta.first' => 1, 'meta.second-one' => null]));

        static::assertSame('SET #meta.#first = :h_0_meta_2efirst REMOVE #meta.#second_2done', $expression);
        static::assertSame(['#meta' => 'meta', '#first' => 'first', '#second_2done' => 'second-one'], $context->names);
    }

    /**
     * DynamoDB has no null attribute, so removing a field that may not be null would leave a row that fails
     * to read back. A default does not help: an update never falls back on one.
     */
    public function testAFieldThatMayNotBeNullCannotBeRemoved(): void
    {
        $this->expectException(FieldMissingSerializedValueException::class);

        $this->compile(new UpdateExpression(['count' => null]));
    }

    public function testAnUnknownFieldIsRefusedWhetherSetOrRemoved(): void
    {
        foreach (['value', null] as $value) {
            try {
                $this->compile(new UpdateExpression(['nope' => $value]));
                static::fail('An unknown field should be refused.');
            } catch (UnknownFieldException $exception) {
                static::assertSame('nope', $exception->field);
            }
        }
    }

    public function testNothingToWriteCompilesToNothing(): void
    {
        [$expression, $context] = $this->compile(new UpdateExpression());

        static::assertNull($expression);
        static::assertSame([], $context->names);
        static::assertSame([], $context->values);
    }

    /**
     * DynamoDB takes each clause once, so actions are gathered into their clause whatever order they came
     * in, and fields come before actions within the SET clause.
     */
    public function testActionsAreGatheredIntoTheirClauses(): void
    {
        [$expression] = $this->compile(
            Update::with(
                Update::add('count', 2),
                Update::remove('name'),
                Update::setIfNotExists('ratio', 1.5),
                Update::delete('tags', ['old']),
                Update::set('id', 'x'),
            ),
        );

        static::assertSame(
            'SET #id = :h_0_id, #ratio = if_not_exists(#ratio, :h_2_ratio) REMOVE #name ADD #count :h_1_count DELETE #tags :h_3_tags',
            $expression,
        );
    }

    public function testIncrementAndDecrementAddTheStep(): void
    {
        [$increment, $incrementContext] = $this->compile(Update::increment('count', 2));
        [$decrement, $decrementContext] = $this->compile(Update::decrement('ratio', 0.25));

        static::assertSame('ADD #count :h_0_count', $increment);
        static::assertSame('2', ($incrementContext->values[':h_0_count'] ?? null)?->getN());
        static::assertSame('ADD #ratio :h_0_ratio', $decrement);
        static::assertSame('-0.25', ($decrementContext->values[':h_0_ratio'] ?? null)?->getN());
    }

    public function testIncrementReachesIntoAMapEntry(): void
    {
        [$expression] = $this->compile(Update::increment('meta.visits'));

        static::assertSame('ADD #meta.#visits :h_0_meta_2evisits', $expression);
    }

    /**
     * The step goes through the field's serializer, so an `int` field refuses a fraction it would read
     * back truncated.
     */
    public function testIncrementOfAnIntFieldRefusesAFractionalStep(): void
    {
        $this->expectException(WrongTypeException::class);

        $this->compile(Update::increment('count', 0.5));
    }

    public function testAppendCountsAMissingListAsEmpty(): void
    {
        [$expression, $context] = $this->compile(Update::append('tags', ['c']));

        static::assertSame('SET #tags = list_append(if_not_exists(#tags, :h_0_tags), :h_1_tags)', $expression);
        static::assertEquals([
            ':h_0_tags' => new AttributeValue(['L' => []]),
            ':h_1_tags' => new AttributeValue(['L' => [new AttributeValue(['S' => 'c'])]]),
        ], $context->values);
    }

    public function testPrependPutsTheValuesFirst(): void
    {
        [$expression] = $this->compile(Update::prepend('tags', ['a']));

        static::assertSame('SET #tags = list_append(:h_1_tags, if_not_exists(#tags, :h_0_tags))', $expression);
    }

    public function testAddAndDeleteTakeTheOperandAsTheFieldSerializesIt(): void
    {
        [$add, $addContext] = $this->compile(Update::add('count', 1));
        [$delete, $deleteContext] = $this->compile(Update::delete('tags', ['a']));

        static::assertSame('ADD #count :h_0_count', $add);
        static::assertEquals([':h_0_count' => new AttributeValue(['N' => '1'])], $addContext->values);
        static::assertSame('DELETE #tags :h_0_tags', $delete);
        static::assertEquals([':h_0_tags' => new AttributeValue(['L' => [new AttributeValue(['S' => 'a'])]])], $deleteContext->values);
    }

    public function testAnActionOfYourOwnCompilesIntoItsClause(): void
    {
        $copy = new class implements UpdateActionInterface {
            public function getClause(): UpdateClause
            {
                return UpdateClause::Set;
            }

            public function compile(ExpressionCompileContext $context): string
            {
                return "{$context->attribute('ratio')} = {$context->attribute('count')}";
            }
        };

        [$expression, $context] = $this->compile(Update::with($copy, Update::remove('name')));

        static::assertSame('SET #ratio = #count REMOVE #name', $expression);
        static::assertSame(['#name' => 'name', '#ratio' => 'ratio', '#count' => 'count'], $context->names);
    }

    public function testAnActionThatContributesNothingLeavesNoClause(): void
    {
        $nothing = new class implements UpdateActionInterface {
            public function getClause(): UpdateClause
            {
                return UpdateClause::Add;
            }

            public function compile(ExpressionCompileContext $context): ?string
            {
                return null;
            }
        };

        [$expression] = $this->compile(Update::with($nothing));

        static::assertNull($expression);
    }

    /**
     * `null` is absence, and setting absence where nothing is stored changes nothing. Removing the path instead
     * would drop a stored value the action promises to keep.
     */
    public function testASetIfNotExistsWithoutAValueWritesNothing(): void
    {
        [$expression, $context] = $this->compile(Update::with(Update::setIfNotExists('name', null), Update::set('count', 1)));

        static::assertSame('SET #count = :h_0_count', $expression);
        static::assertSame(['#count' => 'count'], $context->names);
    }

    /**
     * A missing list would otherwise be created, as an empty one.
     */
    public function testAnAppendWithoutElementsWritesNothing(): void
    {
        [$expression, $context] = $this->compile(Update::with(Update::append('tags', []), Update::set('count', 1)));

        static::assertSame('SET #count = :h_0_count', $expression);
        static::assertSame(['#count' => 'count'], $context->names);
    }

    #[DataProvider('nullOperandsProvider')]
    public function testAddAndDeleteRefuseANullOperand(UpdateExpression $update, string $message): void
    {
        $this->expectException(NullOperandException::class);
        $this->expectExceptionMessage($message);

        $this->compile($update);
    }

    /**
     * @return iterable<string, array{UpdateExpression, string}>
     */
    public static function nullOperandsProvider(): iterable
    {
        yield 'ADD' => [Update::add('count', null), 'Operand for field "count"'];
        yield 'DELETE' => [Update::delete('tags', null), 'Operand for field "tags"'];
    }

    public function testAnActionTakesBackItsValueAndKeepsTheRest(): void
    {
        $prepend = new ListAppendAction('tags', ['a'], prepend: true);

        static::assertEquals(new ListAppendAction('tags', ['b'], prepend: true), $prepend->withValue(['b']));
        static::assertEquals(new SetIfNotExistsAction('name', 'b'), new SetIfNotExistsAction('name', 'a')->withValue('b'));
        static::assertEquals(new ListAppendAction('tags', ['a'], prepend: true), $prepend);
    }

    /**
     * The action takes back whatever the normalizer left, and compiling refuses it as the list field does.
     */
    public function testAnAppendRefusesElementsThatAreNoListOnCompile(): void
    {
        $this->expectException(WrongTypeException::class);
        $this->expectExceptionMessage('Expected type "array" for field "tags" in item "counter", got "string"');

        $this->compile(Update::with(new ListAppendAction('tags', ['a'])->withValue('b')));
    }

    public function testWithExtendsTheExpressionAndLeavesItAlone(): void
    {
        $base = Update::with(Update::set('name', 'a'), Update::increment('count'));

        $extended = $base->with(Update::set('name', 'b'), Update::remove('ratio'), Update::decrement('count'));

        static::assertSame(['name' => 'b', 'ratio' => null], $extended->fields);
        static::assertEquals([new AddAction('count', 1), new AddAction('count', -1)], $extended->actions);
        static::assertSame(['name' => 'a'], $base->fields);
        static::assertEquals([new AddAction('count', 1)], $base->actions);
    }

    public function testSettingAndRemovingWholeFieldsOnlySetsWholeFields(): void
    {
        static::assertTrue(Update::setFields(['name' => null, 'count' => 1])->onlySetsWholeFields(CounterDefinition::create()));
    }

    /**
     * A path into an attribute keeps the rest of it as stored, and an action computes from the stored value.
     */
    public function testAPathIntoAnAttributeOrAnActionIsMoreThanSettingWholeFields(): void
    {
        $definition = CounterDefinition::create();

        static::assertFalse(Update::setFields(['count' => 1, 'meta.first' => 1])->onlySetsWholeFields($definition));
        static::assertFalse(Update::remove('tags[0]')->onlySetsWholeFields($definition));
        static::assertFalse(Update::with(Update::set('count', 1), Update::setIfNotExists('name', 'a'))->onlySetsWholeFields($definition));
    }

    /**
     * @return array{?string, ExpressionCompileContext}
     */
    private function compile(UpdateExpression $update): array
    {
        $context = new ExpressionCompileContext(CounterDefinition::create(), self::PREFIX);

        return [$update->compile($context), $context];
    }
}
