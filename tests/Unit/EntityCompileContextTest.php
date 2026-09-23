<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit;

use Shopware\DynamodbDalBundle\EntityCompileContext;
use Shopware\DynamodbDalBundle\Serializer\Field\IntFieldSerializer;
use Shopware\DynamodbDalBundle\Serializer\Field\ListFieldSerializer;
use Shopware\DynamodbDalBundle\Serializer\Field\StringFieldSerializer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

#[CoversClass(EntityCompileContext::class)]
class EntityCompileContextTest extends TestCase
{
    public function testConstructor(): void
    {
        $container = new ContainerBuilder();
        $context = new EntityCompileContext(
            $container,
            'item_name',
            'App\SomeEntity',
            ['serializer.id' => ['tag' => 'attr']],
        );

        static::assertSame($container, $context->container);
        static::assertSame('item_name', $context->itemName);
        static::assertSame('App\SomeEntity', $context->entityClass);
        static::assertSame(['serializer.id' => ['tag' => 'attr']], $context->fieldSerializers);
    }

    public function testFindFieldSerializerReturnsTheSupportingServiceId(): void
    {
        $context = $this->context([
            StringFieldSerializer::class => [],
            IntFieldSerializer::class => [],
        ]);

        static::assertSame(IntFieldSerializer::class, $context->findFieldSerializer('int', null));
        static::assertSame(StringFieldSerializer::class, $context->findFieldSerializer('string', null));
    }

    public function testFindFieldSerializerReturnsFirstMatchInDeclarationOrder(): void
    {
        // Both serializers are scanned in array order; the first whose supports() matches wins.
        $stringFirst = $this->context([
            StringFieldSerializer::class => [],
            IntFieldSerializer::class => [],
        ]);
        $intFirst = $this->context([
            IntFieldSerializer::class => [],
            StringFieldSerializer::class => [],
        ]);

        static::assertSame(StringFieldSerializer::class, $stringFirst->findFieldSerializer('string', null));
        static::assertSame(IntFieldSerializer::class, $intFirst->findFieldSerializer('int', null));
    }

    public function testFindFieldSerializerReturnsFalseWhenNoneSupportsTheType(): void
    {
        $context = $this->context([
            StringFieldSerializer::class => [],
            IntFieldSerializer::class => [],
        ]);

        static::assertFalse($context->findFieldSerializer('float', null));
    }

    public function testFindFieldSerializerReturnsFalseWithoutRegisteredSerializers(): void
    {
        static::assertFalse($this->context([])->findFieldSerializer('string', null));
    }

    public function testFindFieldSerializerSkipsServiceIdsThatAreNotFieldSerializers(): void
    {
        // A service id that does not resolve to an AbstractFieldSerializer subclass is ignored, even
        // though its tag attributes are present.
        $context = $this->context([
            \stdClass::class => [],
            StringFieldSerializer::class => [],
        ]);

        static::assertSame(StringFieldSerializer::class, $context->findFieldSerializer('string', null));
    }

    public function testFindFieldSerializerPassesDocblockTypeThrough(): void
    {
        // ListFieldSerializer::supports() only matches when the docblock type is a list<...>; this proves
        // both the phpType and the docblock type reach supports().
        $context = $this->context([
            ListFieldSerializer::class => [],
            StringFieldSerializer::class => [],
        ]);

        static::assertSame(ListFieldSerializer::class, $context->findFieldSerializer('array', 'list<string>'));
        static::assertFalse($context->findFieldSerializer('array', null));
    }

    /**
     * @param array<string, array<string, mixed>> $fieldSerializers
     */
    private function context(array $fieldSerializers): EntityCompileContext
    {
        return new EntityCompileContext(
            new ContainerBuilder(),
            'item_name',
            'App\SomeEntity',
            $fieldSerializers,
        );
    }
}
