<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Definition;

use Shopware\DynamodbDalBundle\Tests\Unit\Fixtures\CustomerEntity;
use Shopware\DynamodbDalBundle\Tests\Unit\Fixtures\OrderEntity;
use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\EntityDefinitionRegistry;
use Shopware\DynamodbDalBundle\Definition\KeySchema;
use Shopware\DynamodbDalBundle\Exception\UnknownEntityDefinitionException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EntityDefinitionRegistry::class)]
class EntityDefinitionRegistryTest extends TestCase
{
    public function testResolvesByLogicalName(): void
    {
        $order = $this->definition('order', 'prod-order', OrderEntity::class);
        $registry = new EntityDefinitionRegistry(['order' => $order]);

        static::assertSame($order, $registry->get('order'));
        static::assertTrue($registry->has('order'));
    }

    public function testResolvesByPhysicalTableName(): void
    {
        $order = $this->definition('order', 'prod-order', OrderEntity::class);
        $customer = $this->definition('customer', 'prod-customer', CustomerEntity::class);
        $registry = new EntityDefinitionRegistry(['order' => $order, 'customer' => $customer]);

        static::assertSame($order, $registry->getByTableName('prod-order'));
        static::assertSame($customer, $registry->getByTableName('prod-customer'));
    }

    public function testGetThrowsForUnknownLogicalName(): void
    {
        $registry = new EntityDefinitionRegistry([]);

        static::assertFalse($registry->has('order'));

        $this->expectException(UnknownEntityDefinitionException::class);
        $registry->get('order');
    }

    public function testGetByTableNameThrowsForUnknownPhysicalTable(): void
    {
        $registry = new EntityDefinitionRegistry(['order' => $this->definition('order', 'prod-order', OrderEntity::class)]);

        $this->expectException(UnknownEntityDefinitionException::class);
        $registry->getByTableName('prod-unknown');
    }

    /**
     * @param class-string<AbstractEntity> $entityClass
     *
     * @return EntityDefinition<AbstractEntity>
     */
    private function definition(string $logicalName, string $physicalTable, string $entityClass): EntityDefinition
    {
        return new EntityDefinition(
            $logicalName,
            $physicalTable,
            $entityClass,
            null,
            [],
            new KeySchema('id'),
        );
    }
}
