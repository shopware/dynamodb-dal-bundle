<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit;

use Shopware\DynamodbDalBundle\AbstractEntity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AbstractEntity::class)]
class AbstractEntityTest extends TestCase
{
    public function testGetVars(): void
    {
        $entity = new class extends AbstractEntity {
            protected string $id;

            protected string $name;
        };

        $entity->setVars([
            'id' => 'test-id',
            'name' => 'test-name',
        ]);

        $vars = $entity->getVars();

        static::assertSame('test-id', $vars['id']);
        static::assertSame('test-name', $vars['name']);
    }

    public function testSetVarsWithNonExistingProperties(): void
    {
        $entity = new class extends AbstractEntity {
            protected string $id;
        };

        $entity->setVars([
            'id' => 'test-id',
            // non-existing property 'name' should be ignored
            'name' => 'test-name',
        ]);

        $vars = $entity->getVars();

        static::assertSame('test-id', $vars['id']);
        static::assertArrayNotHasKey('name', $vars);
    }
}
