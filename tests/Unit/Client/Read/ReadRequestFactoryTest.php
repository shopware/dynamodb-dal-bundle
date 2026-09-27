<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Client\Read;

use Shopware\DynamodbDalBundle\Client\Cursor;
use Shopware\DynamodbDalBundle\Client\Input\GetInput;
use Shopware\DynamodbDalBundle\Client\Input\QueryInput;
use Shopware\DynamodbDalBundle\Client\Input\RefreshInput;
use Shopware\DynamodbDalBundle\Client\Input\ScanInput;
use Shopware\DynamodbDalBundle\Client\Key;
use Shopware\DynamodbDalBundle\Client\Read\PreparedSearch;
use Shopware\DynamodbDalBundle\Client\Read\ReadRequestFactory;
use Shopware\DynamodbDalBundle\Definition\EntityDefinitionRegistry;
use Shopware\DynamodbDalBundle\Definition\IndexSchema;
use Shopware\DynamodbDalBundle\Exception\InvalidCursorException;
use Shopware\DynamodbDalBundle\Exception\UnknownIndexException;
use Shopware\DynamodbDalBundle\Expression\Filter;
use Shopware\DynamodbDalBundle\Expression\FilterCompiler;
use Shopware\DynamodbDalBundle\Serializer\Serializer;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\NormalEntity;
use AsyncAws\DynamoDb\Enum\Select;
use AsyncAws\DynamoDb\Input\QueryInput as DynamoDbQueryInput;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReadRequestFactory::class)]
#[CoversClass(PreparedSearch::class)]
class ReadRequestFactoryTest extends TestCase
{
    private ReadRequestFactory $factory;

    protected function setUp(): void
    {
        $definition = NormalEntity::createDefinition(indexes: ['nameIndex' => new IndexSchema('nameIndex', 'name')]);
        $serializer = new Serializer();

        $this->factory = new ReadRequestFactory($serializer, new FilterCompiler(), new EntityDefinitionRegistry([$definition->getName() => $definition]));
    }

    public function testAGetReadsItsKeysAsItAsks(): void
    {
        $request = $this->factory->get(new GetInput([new Key(NormalEntity::class, 'a')], consistentRead: true))->getItemRequest();

        static::assertEquals([
            'TableName' => 'normal',
            'Key' => ['autofilledId' => new AttributeValue(['S' => 'a'])],
            'ConsistentRead' => true,
        ], $request);
    }

    public function testARefreshReadsEachEntityBackIntoItself(): void
    {
        $entity = new NormalEntity()->setAutofilledId('a')->setRequired('req');

        $read = $this->factory->refresh(new RefreshInput([$entity]));
        $key = $read->keyOf('normal', ['autofilledId' => new AttributeValue(['S' => 'a'])]);

        static::assertNotNull($key);
        static::assertSame([$entity], $read->targets($key));
    }

    /**
     * An item is resumed after by its table key, and by the index key too where a query reads an index.
     */
    public function testASearchResumesAfterTheTableKeyAndTheKeyOfTheIndexItReads(): void
    {
        static::assertSame(['autofilledId' => true], $this->factory->search(new ScanInput(NormalEntity::class))->startKeyFields);
        static::assertSame(
            ['autofilledId' => true, 'name' => true],
            $this->factory->search(new QueryInput(NormalEntity::class, Filter::keyFilter(Filter::equals('name', 'n')), index: 'nameIndex'))->startKeyFields,
        );
    }

    public function testASearchResumesFromItsCursor(): void
    {
        $key = ['autofilledId' => new AttributeValue(['S' => 'a'])];

        $input = $this->factory->search(new ScanInput(NormalEntity::class, cursor: new Cursor($key)->encode()))->input;

        static::assertEquals($key, $input->getExclusiveStartKey());
    }

    /**
     * A backward cursor reads the query in reverse from its key, towards the start.
     */
    public function testABackwardCursorFlipsTheOrderOfAQuery(): void
    {
        $cursor = new Cursor(['autofilledId' => new AttributeValue(['S' => 'a'])], backward: true)->encode();

        $input = $this->factory->search(new QueryInput(NormalEntity::class, Filter::keyFilter(Filter::equals('autofilledId', 'a')), cursor: $cursor))->input;

        static::assertInstanceOf(DynamoDbQueryInput::class, $input);
        static::assertFalse($input->getScanIndexForward());
    }

    public function testASearchRefusesACursorOfAnotherKey(): void
    {
        $this->expectException(InvalidCursorException::class);

        $this->factory->search(new ScanInput(NormalEntity::class, cursor: new Cursor(['tenantId' => new AttributeValue(['S' => 'x'])])->encode()));
    }

    public function testASearchRefusesABackwardCursorOnAScan(): void
    {
        $this->expectException(InvalidCursorException::class);

        $this->factory->search(new ScanInput(NormalEntity::class, cursor: new Cursor(['autofilledId' => new AttributeValue(['S' => 'a'])], backward: true)->encode()));
    }

    /**
     * A token is edited easily, so a key value of another type than its field is stored as is refused before DynamoDB
     * rejects it with an error of its own.
     */
    public function testASearchRefusesACursorWhoseKeyIsOfAnotherTypeThanItsField(): void
    {
        try {
            $this->factory->search(new ScanInput(NormalEntity::class, cursor: new Cursor(['autofilledId' => new AttributeValue(['N' => '1'])])->encode()));
            static::fail('The cursor is taken');
        } catch (InvalidCursorException $exception) {
            static::assertSame('key attribute "autofilledId" is of type N, where its field is stored as S', $exception->reason);
        }
    }

    public function testASearchRefusesAnIndexTheDefinitionDoesNotDeclare(): void
    {
        $this->expectException(UnknownIndexException::class);

        $this->factory->search(new QueryInput(NormalEntity::class, Filter::keyFilter(Filter::equals('name', 'n')), index: 'missing'));
    }

    /**
     * One past the limit tells whether another page follows. DynamoDB filters after applying `Limit`, so a filtered
     * search reads full pages instead.
     */
    public function testASearchAsksForOnePastItsLimitUnlessItFilters(): void
    {
        static::assertSame(3, $this->factory->search(new ScanInput(NormalEntity::class, limit: 2))->input->getLimit());
        static::assertNull($this->factory->search(new ScanInput(NormalEntity::class, Filter::equals('name', 'n'), limit: 2))->input->getLimit());
    }

    /**
     * A count takes in every match, so the search's cursor and limit do not narrow it.
     */
    public function testACountSelectsTheCountOfEveryMatch(): void
    {
        $input = $this->factory->count(new ScanInput(
            NormalEntity::class,
            cursor: new Cursor(['autofilledId' => new AttributeValue(['S' => 'a'])])->encode(),
            limit: 2,
        ));

        static::assertSame(Select::COUNT, $input->getSelect());
        static::assertSame([], $input->getExclusiveStartKey());
        static::assertNull($input->getLimit());
    }
}
