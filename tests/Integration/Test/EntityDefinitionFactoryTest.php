<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Integration\Test;

use Shopware\DynamodbDalBundle\Exception\ConditionEmptyException;
use Shopware\DynamodbDalBundle\Exception\WrongTypeException;
use Shopware\DynamodbDalBundle\Serializer\Field\StringFieldSerializer;
use Shopware\DynamodbDalBundle\Test\EntityDefinitionFactory;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\CompilerPass\EntityWithCustomTypeEntity;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\CompilerPass\MoneyFieldSerializer;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\CompilerPass\UnknownHashKeyEntity;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\Entity\NormalizedEntity;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\Entity\NormalizedEntityNormalizer;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\Entity\RecordEntity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EntityDefinitionFactory::class)]
class EntityDefinitionFactoryTest extends TestCase
{
    public function testBuildsTheDefinitionFromTheEntitysAttributes(): void
    {
        $definition = EntityDefinitionFactory::create(RecordEntity::class);

        static::assertSame(RecordEntity::class, $definition->getClass());
        static::assertSame('record', $definition->getName());
        static::assertSame('record', $definition->getTable());
        static::assertSame(['tenantId', 'id'], $definition->getKeySchema()->getFields());
        static::assertSame(['status', 'createdAt'], $definition->getIndex('statusIndex')?->keySchema->getFields());
        static::assertInstanceOf(StringFieldSerializer::class, $definition->getFieldDefinition('name')?->getSerializer());
        static::assertNull($definition->getNormalizer());
    }

    public function testTakesThePhysicalTableGiven(): void
    {
        static::assertSame('records-test', EntityDefinitionFactory::create(RecordEntity::class, table: 'records-test')->getTable());
    }

    public function testBuildsTheNormalizerTheEntityNamesWithoutArguments(): void
    {
        static::assertInstanceOf(NormalizedEntityNormalizer::class, EntityDefinitionFactory::create(NormalizedEntity::class)->getNormalizer());
    }

    public function testTakesTheNormalizerGiven(): void
    {
        $normalizer = new NormalizedEntityNormalizer();

        static::assertSame($normalizer, EntityDefinitionFactory::create(NormalizedEntity::class, normalizer: $normalizer)->getNormalizer());
    }

    public function testASerializerGivenServesTheTypeItClaims(): void
    {
        $serializer = new MoneyFieldSerializer();

        $definition = EntityDefinitionFactory::create(EntityWithCustomTypeEntity::class, [$serializer]);

        static::assertSame($serializer, $definition->getFieldDefinition('amount')?->getSerializer());
    }

    public function testRefusesATypeNoSerializerClaimsAsTheContainerBuildDoes(): void
    {
        $this->expectException(\LogicException::class);

        EntityDefinitionFactory::create(EntityWithCustomTypeEntity::class);
    }

    public function testRefusesAWronglyDeclaredEntityAsTheContainerBuildDoes(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('doesNotExist');

        EntityDefinitionFactory::create(UnknownHashKeyEntity::class);
    }

    /**
     * A test double that throws an exception of the DAL needs the definition it names.
     */
    public function testTheDefinitionBuildsTheExceptionsOfTheDal(): void
    {
        $definition = EntityDefinitionFactory::create(RecordEntity::class);
        $field = $definition->getFieldDefinition('name');
        static::assertNotNull($field);

        static::assertStringContainsString('"record"', new ConditionEmptyException($definition)->getMessage());
        static::assertStringContainsString('"name"', new WrongTypeException($field, 'string', 1)->getMessage());
    }
}
