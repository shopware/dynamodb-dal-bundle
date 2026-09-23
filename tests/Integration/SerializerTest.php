<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Integration;

use AsyncAws\DynamoDb\ValueObject\AttributeValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Serializer\SerializedResult;
use Shopware\DynamodbDalBundle\Serializer\Serializer;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\Entity\NormalizedEntity;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\Entity\NormalizedEntityNormalizer;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\Entity\RecordEntity;
use Symfony\Component\DependencyInjection\Container;

/**
 * Drives the real {@see Serializer} against a compiled {@see EntityDefinition} — its real normalizer and
 * field serializers, no mocks — and asserts the {@see SerializedResult} it produces: that
 * `getUpdateExpression()` builds the right SET / REMOVE / combined clauses with matching placeholder
 * maps, and that `apply()` writes the normalized values back onto an entity.
 */
#[CoversClass(Serializer::class)]
#[CoversClass(SerializedResult::class)]
class SerializerTest extends TestCase
{
    use CompilesContainerTrait;

    private Container $container;

    protected function setUp(): void
    {
        $this->container = $this->compileContainer(
            [RecordEntity::class => 'phpunit-record', NormalizedEntity::class => 'phpunit-normalized'],
            [NormalizedEntityNormalizer::class],
            [Serializer::class],
        );
    }

    public function testGetUpdateExpressionBuildsASetClauseForASingleValue(): void
    {
        $expression = $this->serializer()->serialize($this->definition('record'), ['name' => 'a name'])->getUpdateExpression();

        static::assertSame('SET #name = :sv_name', $expression['UpdateExpression']);
        static::assertSame(['#name' => 'name'], $expression['ExpressionAttributeNames'] ?? null);
        static::assertEquals(
            [':sv_name' => new AttributeValue(['S' => 'a name'])],
            $expression['ExpressionAttributeValues'] ?? null,
        );
    }

    public function testGetUpdateExpressionBuildsASetClauseForSeveralValues(): void
    {
        $expression = $this->serializer()->serialize($this->definition('record'), [
            'name' => 'a name',
            'counter' => 7,
        ])->getUpdateExpression();

        // Both assignments live in one SET clause; ordering follows the provided field order.
        static::assertSame('SET #name = :sv_name, #counter = :sv_counter', $expression['UpdateExpression']);
        static::assertSame(['#name' => 'name', '#counter' => 'counter'], $expression['ExpressionAttributeNames'] ?? null);
        static::assertEquals([
            ':sv_name' => new AttributeValue(['S' => 'a name']),
            ':sv_counter' => new AttributeValue(['N' => '7']),
        ], $expression['ExpressionAttributeValues'] ?? null);
    }

    public function testGetUpdateExpressionBuildsARemoveClauseForNullValues(): void
    {
        $expression = $this->serializer()->serialize($this->definition('record'), ['name' => null])->getUpdateExpression();

        static::assertSame('REMOVE #name', $expression['UpdateExpression']);
        static::assertSame(['#name' => 'name'], $expression['ExpressionAttributeNames'] ?? null);
        static::assertArrayNotHasKey('ExpressionAttributeValues', $expression);
    }

    public function testGetUpdateExpressionCombinesSetAndRemove(): void
    {
        $expression = $this->serializer()->serialize($this->definition('record'), [
            'name' => null,
            'counter' => 7,
        ])->getUpdateExpression();

        static::assertSame('SET #counter = :sv_counter REMOVE #name', $expression['UpdateExpression']);
        static::assertSame(['#name' => 'name', '#counter' => 'counter'], $expression['ExpressionAttributeNames'] ?? null);
    }

    public function testGetUpdateExpressionForAnEmptyFieldSetProducesNoClauses(): void
    {
        $expression = $this->serializer()->serialize($this->definition('record'), [])->getUpdateExpression();

        static::assertSame('', $expression['UpdateExpression']);
        static::assertArrayNotHasKey('ExpressionAttributeNames', $expression);
        static::assertArrayNotHasKey('ExpressionAttributeValues', $expression);
    }

    public function testGetUpdateExpressionAddressesOneMapEntry(): void
    {
        $expression = $this->serializer()->serialize($this->definition('record'), ['meta.kind' => 'invoice'])->getUpdateExpression();

        static::assertSame('SET #meta.#kind = :sv_meta_2ekind', $expression['UpdateExpression']);
        static::assertSame(['#meta' => 'meta', '#kind' => 'kind'], $expression['ExpressionAttributeNames'] ?? null);
    }

    public function testApplyWritesSetValuesBackOntoTheEntity(): void
    {
        $entity = RecordEntity::create('tenant-1', 'a', name: 'before');

        $this->serializer()->serialize($this->definition('record'), ['name' => 'after'])->apply($entity);

        static::assertSame('after', $entity->name);
    }

    public function testApplyClearsRemovedValuesOnTheEntity(): void
    {
        $entity = RecordEntity::create('tenant-1', 'a', name: 'before');

        $this->serializer()->serialize($this->definition('record'), ['name' => null])->apply($entity);

        static::assertNull($entity->name);
    }

    public function testApplyRoundTripsAFullEntitysNormalizedValues(): void
    {
        $entity = RecordEntity::create('tenant-1', 'a', name: 'kept', counter: 3, tags: ['x']);
        $entity->meta = ['k' => 'v'];

        $this->serializer()->serialize($this->definition('record'), $entity)->apply($entity);

        static::assertSame('tenant-1', $entity->tenantId);
        static::assertSame('a', $entity->id);
        static::assertSame('kept', $entity->name);
        static::assertSame(3, $entity->counter);
        static::assertSame(['x'], $entity->tags);
        static::assertSame(['k' => 'v'], $entity->meta);
    }

    public function testApplyWritesWhatTheNormalizerGeneratedOntoTheEntity(): void
    {
        $entity = NormalizedEntity::create('tenant-1', 'invoice');

        $this->serializer()->serialize($this->definition('normalized'), $entity)->apply($entity);

        static::assertSame('tenant-1#invoice', $entity->pk);
        static::assertTrue(isset($entity->id));
        static::assertSame(1_700_000_000, $entity->createdAt->getTimestamp());
    }

    private function serializer(): Serializer
    {
        $serializer = $this->container->get('test.' . Serializer::class);
        static::assertInstanceOf(Serializer::class, $serializer);

        return $serializer;
    }

    /**
     * @return EntityDefinition<AbstractEntity>
     */
    private function definition(string $name): EntityDefinition
    {
        $definition = $this->container->get('dal.definition.' . $name);
        static::assertInstanceOf(EntityDefinition::class, $definition);

        return $definition;
    }
}
