<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Serializer;

use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\FieldDefinition;
use Shopware\DynamodbDalBundle\Definition\FieldPath;
use Shopware\DynamodbDalBundle\Definition\KeySchema;
use Shopware\DynamodbDalBundle\Serializer\Field\AbstractFieldSerializer;
use Shopware\DynamodbDalBundle\Serializer\NormalizerOperation;
use Shopware\DynamodbDalBundle\Serializer\SerializedFieldResult;
use Shopware\DynamodbDalBundle\Serializer\SerializedResult;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\NormalEntity;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SerializedResult::class)]
class SerializedResultTest extends TestCase
{
    public function testGetters(): void
    {
        $definition = $this->createEntityDefinition();

        $idField = new SerializedFieldResult(
            $this->parse($definition, 'autofilledId'),
            new AttributeValue(['S' => '00000000-0000-0000-0000-000000000000']),
        );
        $nameField = new SerializedFieldResult($this->parse($definition, 'name'), new AttributeValue(['S' => 'test']));

        $result = new SerializedResult(
            $definition,
            ['autofilledId' => $idField, 'name' => $nameField],
            ['autofilledId' => '00000000-0000-0000-0000-000000000000', 'name' => 'test'],
            NormalizerOperation::Put,
        );

        $fields = [
            'autofilledId' => new AttributeValue(['S' => '00000000-0000-0000-0000-000000000000']),
            'name' => new AttributeValue(['S' => 'test']),
        ];

        static::assertEquals($fields, $result->getFields());
        static::assertEquals(['Item' => $fields], $result->getPutExpression());
    }

    /**
     * Handed on in the row's shape; turning it back into entity values is the writer's job, not this one's.
     */
    public function testGetNormalizedFieldsReturnsWhatWasSerialized(): void
    {
        $definition = $this->createEntityDefinition();
        $fields = ['autofilledId' => '00000000-0000-0000-0000-000000000000', 'name' => 'test'];

        $result = new SerializedResult($definition, [], $fields, NormalizerOperation::Update);

        static::assertSame($fields, $result->getNormalizedFields());
        static::assertSame($definition, $result->getEntityDefinition());
        static::assertSame(NormalizerOperation::Update, $result->getOperation());
    }

    /**
     * @param EntityDefinition<NormalEntity> $definition
     */
    private function parse(EntityDefinition $definition, string $path): FieldPath
    {
        $parsed = FieldPath::tryParse($definition, $path);
        static::assertNotNull($parsed);

        return $parsed;
    }

    private function createEntityDefinition(): EntityDefinition
    {
        $serializer = $this->createMock(AbstractFieldSerializer::class);
        $serializer->expects(static::never())->method(static::anything());

        /** @var FieldDefinition<NormalEntity> $idField */
        $idField = new FieldDefinition(
            'autofilledId',
            'string',
            false,
            false,
            null,
            $serializer,
        );

        /** @var FieldDefinition<NormalEntity> $nameField */
        $nameField = new FieldDefinition(
            'name',
            'string',
            true,
            true,
            null,
            $serializer,
        );

        /** @var FieldDefinition<NormalEntity> $abcField */
        $abcField = new FieldDefinition(
            'abc',
            'string',
            false,
            true,
            'sdf',
            $serializer,
        );

        return new EntityDefinition(
            'test',
            'test',
            NormalEntity::class,
            null,
            [
                'autofilledId' => $idField,
                'name' => $nameField,
                'abc' => $abcField,
            ],
            new KeySchema('autofilledId'),
        );
    }
}
