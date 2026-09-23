<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Serializer;

use Shopware\DynamodbDalBundle\Client\Cursor\Cursor;
use Shopware\DynamodbDalBundle\Client\Index;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\FieldDefinition;
use Shopware\DynamodbDalBundle\Definition\IndexSchema;
use Shopware\DynamodbDalBundle\Definition\KeySchema;
use Shopware\DynamodbDalBundle\Exception\FieldDeserializationException;
use Shopware\DynamodbDalBundle\Exception\FieldMissingDeserializedValueException;
use Shopware\DynamodbDalBundle\Exception\FieldMissingSerializedValueException;
use Shopware\DynamodbDalBundle\Exception\MissingAttributeValueException;
use Shopware\DynamodbDalBundle\Exception\UnknownFieldException;
use Shopware\DynamodbDalBundle\Serializer\AbstractNormalizer;
use Shopware\DynamodbDalBundle\Serializer\Field\AbstractFieldSerializer;
use Shopware\DynamodbDalBundle\Serializer\Field\DateTimeFieldSerializer;
use Shopware\DynamodbDalBundle\Serializer\Field\StringFieldSerializer;
use Shopware\DynamodbDalBundle\Serializer\Serializer;
use Shopware\DynamodbDalBundle\Tests\Unit\DynamoDbResultTestTrait;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\NormalEntity;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\NormalNormalizer;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Serializer::class)]
class SerializerTest extends TestCase
{
    use DynamoDbResultTestTrait;

    /**
     * @var EntityDefinition<NormalEntity>
     */
    private EntityDefinition $definition;

    private Serializer $serializer;

    protected function setUp(): void
    {
        $this->definition = NormalEntity::createDefinition();
        $this->serializer = new Serializer();
    }

    public function testDeserializeWithEmptyInput(): void
    {
        static::assertNull($this->serializer->deserialize($this->definition, self::getItemOutput()));
        static::assertNull($this->serializer->deserialize($this->definition, []));
    }

    public function testDeserializeUnwrapsGetItemOutput(): void
    {
        $result = $this->serializer->deserialize($this->definition, self::getItemOutput([
            'autofilledId' => new AttributeValue(['S' => 'test-id']),
            'name' => new AttributeValue(['S' => 'test-name']),
            'requiredNullableName' => new AttributeValue(['S' => 'test-required-nullable-name']),
            'required' => new AttributeValue(['S' => 'test-required']),
        ]));

        static::assertInstanceOf(NormalEntity::class, $result);
        static::assertSame('test-id', $result->getAutofilledId());
        static::assertSame('test-name', $result->getName());
        static::assertSame('test-required-nullable-name', $result->getRequiredNullableName());
        static::assertSame('test-required', $result->getRequired());
    }

    public function testDeserializeAllFieldsProvided(): void
    {
        $result = $this->serializer->deserialize($this->definition, [
            'autofilledId' => new AttributeValue(['S' => 'test-id']),
            'name' => new AttributeValue(['S' => 'test-name']),
            'requiredNullableName' => new AttributeValue(['S' => 'test-required-nullable-name']),
            'required' => new AttributeValue(['S' => 'test-required']),
        ]);

        static::assertInstanceOf(NormalEntity::class, $result);
        static::assertSame('test-id', $result->getAutofilledId());
        static::assertSame('test-name', $result->getName());
        static::assertSame('test-required-nullable-name', $result->getRequiredNullableName());
        static::assertSame('test-required', $result->getRequired());
    }

    public function testDeserializeFillsAGivenEntityInsteadOfANewOne(): void
    {
        $entity = new NormalEntity()->setAutofilledId('stale')->setName('stale');

        $result = $this->serializer->deserialize($this->definition, [
            'autofilledId' => new AttributeValue(['S' => 'fresh']),
            'required' => new AttributeValue(['S' => 'test-required']),
        ], $entity);

        static::assertSame($entity, $result);
        static::assertSame('fresh', $entity->getAutofilledId());
        // Absent from the item, so absent from the entity: a whole item is the truth, not a patch.
        static::assertNull($entity->getName());
    }

    public function testDeserializeWithOnlyRequiredFields(): void
    {
        $result = $this->serializer->deserialize($this->definition, [
            'autofilledId' => new AttributeValue(['S' => 'test-id']),
            'required' => new AttributeValue(['S' => 'test-required']),
        ]);

        static::assertInstanceOf(NormalEntity::class, $result);
        static::assertSame('test-id', $result->getAutofilledId());
        static::assertNull($result->getName());
        static::assertNull($result->getRequiredNullableName());
        static::assertSame('test-required', $result->getRequired());
    }

    public function testDeserializeWithNormalizerFilledFields(): void
    {
        $result = $this->serializer->deserialize($this->definition, [
            'required' => new AttributeValue(['S' => 'test-required']),
        ]);

        static::assertInstanceOf(NormalEntity::class, $result);
        static::assertSame('test-id', $result->getAutofilledId());
        static::assertNull($result->getName());
        static::assertNull($result->getRequiredNullableName());
        static::assertSame('test-required', $result->getRequired());
    }

    public function testDeserializeWithMissigRequiredFieldsThrows(): void
    {
        static::expectException(FieldMissingDeserializedValueException::class);
        static::expectExceptionMessage('Missing required value for field "required" in item "normal" after deserialization and denormalization.');

        $this->serializer->deserialize($this->definition, [
            'autofilledId' => new AttributeValue(['S' => 'test-id']),
        ]);
    }

    public function testDeserializeWithWrongFieldType(): void
    {
        static::expectException(MissingAttributeValueException::class);
        static::expectExceptionMessage('Missing expected DynamoDB attribute value of type "S" for field "autofilledId" in item "normal"');

        $this->serializer->deserialize($this->definition, [
            'autofilledId' => new AttributeValue(['N' => 'test-id']),
            'required' => new AttributeValue(['S' => 'test-required']),
        ]);
    }

    public function testDeserializeWrapsAFailingFieldSerializer(): void
    {
        $serializer = $this->createMock(AbstractFieldSerializer::class);
        $serializer->expects(static::once())->method('deserialize')->willThrowException(new \RuntimeException('test-deserialization-error'));

        $definition = NormalEntity::createDefinition(fieldSerializer: $serializer);

        static::expectException(FieldDeserializationException::class);
        static::expectExceptionMessage('Field "autofilledId" in item "normal" could not be deserialized');

        $this->serializer->deserialize($definition, [
            'autofilledId' => new AttributeValue(['N' => 'test-id']),
            'required' => new AttributeValue(['S' => 'test-required']),
        ]);
    }

    public function testDeserializeFieldsDeserializesProvidedFields(): void
    {
        $result = $this->serializer->deserializeFields($this->definition, [
            'autofilledId' => new AttributeValue(['S' => 'test-id']),
            'required' => new AttributeValue(['S' => 'test-required']),
        ]);

        static::assertSame([
            'autofilledId' => 'test-id',
            'required' => 'test-required',
        ], $result);
    }

    public function testDeserializeFieldsIgnoresUnknownFields(): void
    {
        $result = $this->serializer->deserializeFields($this->definition, [
            'unknown' => new AttributeValue(['S' => 'test-required']),
        ]);

        static::assertSame([], $result);
    }

    public function testSerializeWithEntity(): void
    {
        $entity = new NormalEntity()
            ->setAutofilledId('test-id')
            ->setName('test-name')
            ->setRequiredNullableName('test-required-nullable-name')
            ->setRequired('test-required');

        $result = $this->serializer->serialize($this->definition, $entity);

        static::assertEquals([
            'autofilledId' => new AttributeValue(['S' => 'test-id']),
            'name' => new AttributeValue(['S' => 'test-name']),
            'requiredNullableName' => new AttributeValue(['S' => 'test-required-nullable-name']),
            'required' => new AttributeValue(['S' => 'test-required']),
        ], $result->getFields());
    }

    public function testSerializeWithEntityFillsUninitialized(): void
    {
        $entity = new NormalEntity()
            ->setAutofilledId('test-id')
            ->setName('test-name')
            ->setRequired('test-required');

        $result = $this->serializer->serialize($this->definition, $entity);

        static::assertEquals([
            'autofilledId' => new AttributeValue(['S' => 'test-id']),
            'name' => new AttributeValue(['S' => 'test-name']),
            'required' => new AttributeValue(['S' => 'test-required']),
        ], $result->getFields());
    }

    public function testSerializeWithNullableField(): void
    {
        $entity = new NormalEntity()
            ->setAutofilledId('test-id')
            ->setName(null)
            ->setRequiredNullableName(null)
            ->setRequired('test-required');

        $result = $this->serializer->serialize($this->definition, $entity);

        static::assertEquals([
            'autofilledId' => new AttributeValue(['S' => 'test-id']),
            'required' => new AttributeValue(['S' => 'test-required']),
        ], $result->getFields());
    }

    public function testSerializeWithFields(): void
    {
        $fields = [
            'autofilledId' => 'test-id',
            'name' => 'test-name',
            'requiredNullableName' => 'test-required-nullable-name',
            'required' => 'test-required',
        ];

        $result = $this->serializer->serialize($this->definition, $fields);

        static::assertEquals([
            'autofilledId' => new AttributeValue(['S' => 'test-id']),
            'name' => new AttributeValue(['S' => 'test-name']),
            'requiredNullableName' => new AttributeValue(['S' => 'test-required-nullable-name']),
            'required' => new AttributeValue(['S' => 'test-required']),
        ], $result->getFields());
    }

    public function testSerializeWithMissingField(): void
    {
        $fields = [
            'autofilledId' => 'test-id',
            'name' => 'test-name',
            'requiredNullableName' => 'test-required-nullable-name',
        ];

        $result = $this->serializer->serialize($this->definition, $fields);

        static::assertEquals([
            'autofilledId' => new AttributeValue(['S' => 'test-id']),
            'name' => new AttributeValue(['S' => 'test-name']),
            'requiredNullableName' => new AttributeValue(['S' => 'test-required-nullable-name']),
        ], $result->getFields());
    }

    public function testSerializeWithUnknownField(): void
    {
        $fields = [
            'autofilledId' => 'test-id',
            'name' => 'test-name',
            'requiredNullableName' => 'test-required-nullable-name',
            'required' => 'test-required',
            'unknownField' => 'test-unknown-field',
        ];

        static::expectException(UnknownFieldException::class);
        static::expectExceptionMessage('Unknown field "unknownField" in item "normal"');

        $this->serializer->serialize($this->definition, $fields);
    }

    public function testCallNormalizerNormalizeWithRightKeys(): void
    {
        $normalizer = $this->createMock(AbstractNormalizer::class);
        $normalizer->expects(static::once())->method('normalize')->with(
            static::callback(static function (array $vars) {
                return \array_key_exists('autofilledId', $vars)
                    && \array_key_exists('name', $vars)
                    && \array_key_exists('requiredNullableName', $vars)
                    && \array_key_exists('required', $vars);
            }),
            [
                'autofilledId' => 'autofilledId',
                'name' => 'name',
                'requiredNullableName' => 'requiredNullableName',
                'required' => 'required',
            ]
        )->willReturnArgument(0);

        $definition = NormalEntity::createDefinition($normalizer);

        $serializer = new Serializer();
        $serializer->serialize($definition, [
            'autofilledId' => 'test-id',
            'name' => 'test-name',
            'requiredNullableName' => 'test-required-nullable-name',
            'required' => 'test-required',
        ]);
    }

    public function testCallNormalizerDenormalizeWithRightKeys(): void
    {
        $normalizer = $this->createMock(AbstractNormalizer::class);
        $normalizer->expects(static::once())->method('denormalize')->with(
            static::callback(static function (array $vars) {
                return \array_key_exists('autofilledId', $vars)
                    && \array_key_exists('name', $vars)
                    && \array_key_exists('requiredNullableName', $vars)
                    && \array_key_exists('required', $vars);
            }),
            [
                'autofilledId' => 'autofilledId',
                'name' => 'name',
                'requiredNullableName' => 'requiredNullableName',
                'required' => 'required',
            ]
        )->willReturnArgument(0);

        $definition = NormalEntity::createDefinition($normalizer);

        $serializer = new Serializer();
        $serializer->deserialize($definition, [
            'autofilledId' => new AttributeValue(['S' => 'test-id']),
            'name' => new AttributeValue(['S' => 'test-name']),
            'requiredNullableName' => new AttributeValue(['S' => 'test-required-nullable-name']),
            'required' => new AttributeValue(['S' => 'test-required']),
        ]);
    }

    public function testDeserializeUsesDefaultValueWhenFieldMissingAndNotNullable(): void
    {
        $serializer = new StringFieldSerializer();
        $definition = new EntityDefinition(
            'normal',
            'normal',
            NormalEntity::class,
            new NormalNormalizer(),
            /** @phpstan-ignore-next-line argument.type (FieldDefinition template covariance in test array) */
            [
                'autofilledId' => new FieldDefinition('autofilledId', 'string', false, true, 'default-id', $serializer),
                'name' => new FieldDefinition('name', 'string', true, false, null, $serializer),
            ],
            new KeySchema('autofilledId'),
        );

        $result = $this->serializer->deserialize($definition, [
            'name' => new AttributeValue(['S' => 'only-name']),
        ]);

        static::assertInstanceOf(NormalEntity::class, $result);
        static::assertSame('default-id', $result->getAutofilledId());
        static::assertSame('only-name', $result->getName());
    }

    public function testSerializeThrowsWhenRequiredFieldNullAndNoDefault(): void
    {
        $serializer = new StringFieldSerializer();
        $definition = new EntityDefinition(
            'normal',
            'normal',
            NormalEntity::class,
            null,
            /** @phpstan-ignore-next-line argument.type (FieldDefinition template covariance in test array) */
            [
                'required' => new FieldDefinition('required', 'string', false, false, null, $serializer),
            ],
            new KeySchema('required'),
        );

        $this->expectException(FieldMissingSerializedValueException::class);
        $this->expectExceptionMessage('Missing required value for field');

        $this->serializer->serialize($definition, ['required' => null]);
    }

    public function testSerializeWithNullNormalizerReturnsFieldsUnchanged(): void
    {
        $serializer = new StringFieldSerializer();
        $definition = new EntityDefinition(
            'normal',
            'normal',
            NormalEntity::class,
            null,
            /** @phpstan-ignore-next-line argument.type (FieldDefinition template covariance in test array) */
            [
                'id' => new FieldDefinition('id', 'string', false, false, null, $serializer),
            ],
            new KeySchema('id'),
        );

        $result = $this->serializer->serialize($definition, ['id' => 'x']);

        static::assertEquals(['id' => new AttributeValue(['S' => 'x'])], $result->getFields());
    }

    public function testDeserializeWithNullNormalizerReturnsFieldsUnchanged(): void
    {
        $serializer = new StringFieldSerializer();
        $definition = new EntityDefinition(
            'normal',
            'normal',
            NormalEntity::class,
            null,
            /** @phpstan-ignore-next-line argument.type (FieldDefinition template covariance in test array) */
            [
                'autofilledId' => new FieldDefinition('autofilledId', 'string', false, false, null, $serializer),
            ],
            new KeySchema('autofilledId'),
        );

        $result = $this->serializer->deserialize($definition, [
            'autofilledId' => new AttributeValue(['S' => 'y']),
        ]);

        static::assertInstanceOf(NormalEntity::class, $result);
        static::assertSame('y', $result->getAutofilledId());
    }

    /**
     * The point of the hash: what a read sends and what it gets back have to land on the same string, or a
     * batched response cannot be paired with the request it answers.
     */
    public function testHashKeyDerivesTheSameStringFromAnEntityAnIndexAndTheRowItself(): void
    {
        $entity = new NormalEntity()->setAutofilledId('id-1')->setRequired('req');

        $fromEntity = $this->serializer->hashKey($this->definition, $entity);
        $fromIndex = $this->serializer->hashKey($this->definition, new Index('id-1'));
        $fromRow = $this->serializer->hashKey($this->definition, [
            'autofilledId' => new AttributeValue(['S' => 'id-1']),
            'name' => new AttributeValue(['S' => 'not part of the key']),
        ]);

        static::assertSame($fromEntity, $fromIndex);
        static::assertSame($fromEntity, $fromRow);
    }

    public function testHashKeyTellsDifferentKeysApart(): void
    {
        static::assertNotSame(
            $this->serializer->hashKey($this->definition, new Index('id-1')),
            $this->serializer->hashKey($this->definition, new Index('id-2')),
        );
    }

    /**
     * A composite key hashes both halves, so two rows sharing a partition key stay distinct.
     */
    public function testHashKeyCoversTheSortKeyToo(): void
    {
        $definition = $this->keyedDefinition();
        $tenant = new AttributeValue(['S' => 'tenant-1']);

        $first = $this->serializer->hashKey($definition, [
            'tenantId' => $tenant,
            'createdAt' => new AttributeValue(['N' => '1700000000']),
        ]);
        $second = $this->serializer->hashKey($definition, [
            'tenantId' => $tenant,
            'createdAt' => new AttributeValue(['N' => '1700000001']),
        ]);

        static::assertNotSame($first, $second);
    }

    public function testDeserializeKeyWithHashOnlyKey(): void
    {
        $key = $this->serializer->deserializeKey($this->definition, [
            'autofilledId' => new AttributeValue(['S' => 'id-1']),
        ]);

        static::assertInstanceOf(Index::class, $key);
        static::assertSame('id-1', $key->hashValue);
        static::assertNull($key->rangeValue);
        static::assertNull($key->index);
    }

    public function testDeserializeKeyIgnoresNonKeyFields(): void
    {
        $key = $this->serializer->deserializeKey($this->definition, [
            'autofilledId' => new AttributeValue(['S' => 'id-1']),
            'name' => new AttributeValue(['S' => 'ignored']),
        ]);

        static::assertInstanceOf(Index::class, $key);
        static::assertSame('id-1', $key->hashValue);
        static::assertNull($key->rangeValue);
    }

    public function testDeserializeKeyReturnsNullWhenHashKeyMissing(): void
    {
        static::assertNull($this->serializer->deserializeKey($this->definition, []));
        static::assertNull($this->serializer->deserializeKey($this->definition, [
            'name' => new AttributeValue(['S' => 'no-key']),
        ]));
    }

    public function testDeserializeKeyWithHashAndRangeKeyDeserializesValues(): void
    {
        $definition = $this->keyedDefinition();
        $createdAt = new \DateTimeImmutable('2026-06-02T10:00:00+00:00');

        $key = $this->serializer->deserializeKey($definition, [
            'tenantId' => new AttributeValue(['S' => 'tenant-1']),
            'createdAt' => new AttributeValue(['N' => (string) $createdAt->getTimestamp()]),
        ]);

        static::assertInstanceOf(Index::class, $key);
        static::assertSame('tenant-1', $key->hashValue);
        static::assertInstanceOf(\DateTimeImmutable::class, $key->rangeValue);
        static::assertEquals($createdAt, $key->rangeValue);
    }

    public function testDeserializeKeyReturnsNullWhenRangeKeyMissing(): void
    {
        $definition = $this->keyedDefinition();

        static::assertNull($this->serializer->deserializeKey($definition, [
            'tenantId' => new AttributeValue(['S' => 'tenant-1']),
        ]));
    }

    public function testSerializeCursorWithPrimaryKeyOnly(): void
    {
        $cursor = new Cursor('normal', new Index('id-1'));

        $fields = $this->serializer->serializeCursor($this->definition, $cursor);

        static::assertEquals(['autofilledId' => new AttributeValue(['S' => 'id-1'])], $fields);
    }

    public function testDeserializeKeyThrowsWhenAKeyValueCannotBeDeserialized(): void
    {
        // The createdAt range key is an N-typed field; an S value cannot be deserialized into it.
        $definition = $this->keyedDefinition();

        static::expectException(FieldDeserializationException::class);

        $this->serializer->deserializeKey($definition, [
            'tenantId' => new AttributeValue(['S' => 'tenant-1']),
            'createdAt' => new AttributeValue(['S' => 'not-a-timestamp']),
        ]);
    }

    public function testSerializeKeyWithEntityReadsKeyFieldsOffTheEntity(): void
    {
        $entity = new NormalEntity()->setAutofilledId('id-1')->setRequired('req');

        $fields = $this->serializer->serializeKey($this->definition, $entity);

        static::assertEquals(['autofilledId' => new AttributeValue(['S' => 'id-1'])], $fields);
    }

    public function testSerializeKeyWithIndex(): void
    {
        $fields = $this->serializer->serializeKey($this->definition, new Index('id-1'));

        static::assertEquals(['autofilledId' => new AttributeValue(['S' => 'id-1'])], $fields);
    }

    public function testSerializeKeyThrowsWhenAKeyValueIsMissingAfterSerialization(): void
    {
        // A null hash value cannot serialize to a key attribute; serializeKey must refuse it rather than
        // emit an incomplete DynamoDB key. (keyedDefinition has no normalizer, so nothing back-fills it.)
        $definition = $this->keyedDefinition();

        static::expectException(FieldMissingSerializedValueException::class);

        $this->serializer->serializeKey($definition, new Index(null, null));
    }

    public function testSerializeCursorMergesPrimaryAndIndexKeys(): void
    {
        $definition = $this->keyedDefinition();
        $createdAt = new \DateTimeImmutable('2026-06-02T10:00:00+00:00');

        $cursor = new Cursor(
            'order',
            new Index('tenant-1', $createdAt),
            new Index('waiting', $createdAt, 'statusCreatedAtIndex'),
        );

        $fields = $this->serializer->serializeCursor($definition, $cursor);

        static::assertEquals([
            'tenantId' => new AttributeValue(['S' => 'tenant-1']),
            'createdAt' => new AttributeValue(['N' => (string) $createdAt->getTimestamp()]),
            'status' => new AttributeValue(['S' => 'waiting']),
        ], $fields);
    }

    public function testSerializeCursorThrowsWhenAKeyValueIsNull(): void
    {
        // serializeCursor goes through serialize(), which refuses a null value for a required key field —
        // so an incomplete ExclusiveStartKey cannot be produced from a null primary key value.
        $definition = $this->keyedDefinition();
        $cursor = new Cursor('order', new Index(null, null));

        static::expectException(FieldMissingSerializedValueException::class);

        $this->serializer->serializeCursor($definition, $cursor);
    }

    public function testSerializeCursorWithAnUnknownIndexYieldsAnEmptyKey(): void
    {
        // An index name the definition does not declare resolves to no key fields; serializeCursor returns
        // only the primary key fields (the unknown index contributes nothing).
        $definition = $this->keyedDefinition();
        $createdAt = new \DateTimeImmutable('2026-06-02T10:00:00+00:00');

        $cursor = new Cursor(
            'order',
            new Index('tenant-1', $createdAt),
            new Index('waiting', $createdAt, 'doesNotExist'),
        );

        $fields = $this->serializer->serializeCursor($definition, $cursor);

        static::assertEquals([
            'tenantId' => new AttributeValue(['S' => 'tenant-1']),
            'createdAt' => new AttributeValue(['N' => (string) $createdAt->getTimestamp()]),
        ], $fields);
    }

    /**
     * A definition with a hash + range primary key and a GSI, for key/cursor (de)serialization.
     *
     * @return EntityDefinition<NormalEntity>
     */
    private function keyedDefinition(): EntityDefinition
    {
        $string = new StringFieldSerializer();
        $dateTime = new DateTimeFieldSerializer();

        /** @var EntityDefinition<NormalEntity> $definition */
        $definition = new EntityDefinition(
            'order',
            'order',
            NormalEntity::class,
            null,
            /** @phpstan-ignore-next-line argument.type (FieldDefinition template covariance in test array) */
            [
                'tenantId' => new FieldDefinition('tenantId', 'string', false, false, null, $string),
                'status' => new FieldDefinition('status', 'string', false, false, null, $string),
                'createdAt' => new FieldDefinition('createdAt', \DateTimeImmutable::class, false, false, null, $dateTime),
            ],
            new KeySchema('tenantId', 'createdAt'),
            ['statusCreatedAtIndex' => new IndexSchema('statusCreatedAtIndex', hashKey: 'status', rangeKey: 'createdAt')],
        );

        return $definition;
    }
}
