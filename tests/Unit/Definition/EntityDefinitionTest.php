<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Definition;

use Shopware\DynamodbDalBundle\Tests\Unit\Fixtures\CustomerEntity;
use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\FieldDefinition;
use Shopware\DynamodbDalBundle\Definition\KeySchema;
use Shopware\DynamodbDalBundle\Serializer\Field\AbstractFieldSerializer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EntityDefinition::class)]
final class EntityDefinitionTest extends TestCase
{
    public function testGetters(): void
    {
        $serializer = $this->createMock(AbstractFieldSerializer::class);
        $serializer->expects(static::never())->method(static::anything());

        /** @var FieldDefinition<CustomerEntity> $idField */
        $idField = new FieldDefinition(
            'id',
            'string',
            false,
            false,
            null,
            $serializer,
        );

        /** @var FieldDefinition<CustomerEntity> $nameField */
        $nameField = new FieldDefinition(
            'name',
            'string',
            true,
            true,
            null,
            $serializer,
        );

        $definition = new EntityDefinition(
            'test',
            'test',
            CustomerEntity::class,
            null,
            [
                'id' => $idField,
                'name' => $nameField,
            ],
            new KeySchema('id'),
        );

        static::assertSame('test', $definition->getName());
        static::assertSame('test', $definition->getTable());
        static::assertSame(CustomerEntity::class, $definition->getClass());
        static::assertNull($definition->getNormalizer());
        static::assertSame(['id' => $idField, 'name' => $nameField], $definition->getFieldDefinitions());
        static::assertSame($idField, $definition->getFieldDefinition('id'));
        static::assertSame($nameField, $definition->getFieldDefinition('name'));
        static::assertNull($definition->getFieldDefinition('some-other-field'));
        static::assertSame(['id', 'name'], $definition->getFieldNames());
    }

    public function testCreateInstance(): void
    {
        $entity = new class extends AbstractEntity {
            protected string $id;

            protected string $name;
        };

        $serializer = $this->createMock(AbstractFieldSerializer::class);
        $serializer->expects(static::never())->method(static::anything());

        $idField = new FieldDefinition(
            'id',
            'string',
            false,
            false,
            null,
            $serializer,
        );

        $nameField = new FieldDefinition(
            'name',
            'string',
            true,
            true,
            null,
            $serializer,
        );

        $definition = new EntityDefinition(
            'test',
            'test',
            $entity::class,
            null,
            [
                'id' => $idField,
                'name' => $nameField,
            ],
            new KeySchema('id'),
        );

        $instance = $definition->createInstance();

        static::assertInstanceOf($entity::class, $instance);
    }
}
