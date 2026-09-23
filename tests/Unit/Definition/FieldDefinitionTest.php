<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Definition;

use Shopware\DynamodbDalBundle\Tests\Unit\Fixtures\CustomerEntity;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\FieldDefinition;
use Shopware\DynamodbDalBundle\Definition\KeySchema;
use Shopware\DynamodbDalBundle\Serializer\Field\AbstractFieldSerializer;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
#[CoversClass(FieldDefinition::class)]
class FieldDefinitionTest extends TestCase
{
    public function testGetters(): void
    {
        $serializer = $this->createMock(AbstractFieldSerializer::class);
        $serializer->expects(static::never())->method(static::anything());

        $definition = new FieldDefinition(
            'id',
            'string',
            false,
            true,
            'default',
            $serializer,
        );

        $entityDefinition = $this->createEntityDefinition();
        $definition->setEntityDefinition($entityDefinition);

        static::assertSame($entityDefinition, $definition->getEntityDefinition());
        static::assertSame('id', $definition->getName());
        static::assertSame('string', $definition->getType());
        static::assertFalse($definition->allowsNull());
        static::assertTrue($definition->hasDefaultValue());
        static::assertSame('default', $definition->getDefaultValue());
        static::assertSame('#id', $definition->getExpressionAttributeName());
        static::assertSame(':id', $definition->getExpressionValueName());
        static::assertSame($serializer, $definition->getSerializer());
    }

    public function testSettingDefinitionTwiceThrows(): void
    {
        $definition = new FieldDefinition(
            'id',
            'string',
            false,
            true,
            'default',
            $this->createMock(AbstractFieldSerializer::class),
        );

        $entityDefinition = $this->createEntityDefinition();

        static::expectException(\LogicException::class);
        static::expectExceptionMessage('Entity definition for field id is already set');

        $definition->setEntityDefinition($entityDefinition);
        $definition->setEntityDefinition($entityDefinition);
    }

    /**
     * @return EntityDefinition<CustomerEntity>
     */
    private function createEntityDefinition(): EntityDefinition
    {
        return new EntityDefinition(
            'customer',
            'customer',
            CustomerEntity::class,
            null,
            [],
            new KeySchema('tenantId', 'label'),
        );
    }
}
