<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Serializer;

use Shopware\DynamodbDalBundle\Client\Key;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\FieldDefinition;
use Shopware\DynamodbDalBundle\Definition\IndexSchema;
use Shopware\DynamodbDalBundle\Definition\KeySchema;
use Shopware\DynamodbDalBundle\Exception\FieldDeserializationException;
use Shopware\DynamodbDalBundle\Exception\FieldMissingDeserializedValueException;
use Shopware\DynamodbDalBundle\Exception\FieldMissingSerializedValueException;
use Shopware\DynamodbDalBundle\Exception\MissingAttributeValueException;
use Shopware\DynamodbDalBundle\Exception\UnknownFieldException;
use Shopware\DynamodbDalBundle\Serializer\Field\AbstractFieldSerializer;
use Shopware\DynamodbDalBundle\Serializer\Field\DateTimeFieldSerializer;
use Shopware\DynamodbDalBundle\Serializer\Field\StringFieldSerializer;
use Shopware\DynamodbDalBundle\Serializer\NormalizerContext;
use Shopware\DynamodbDalBundle\Serializer\NormalizerOperation;
use Shopware\DynamodbDalBundle\Serializer\Serializer;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\NormalEntity;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\NormalNormalizer;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\RecordingNormalizer;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Serializer::class)]
class SerializerTest extends TestCase
{
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
        static::assertNull($this->serializer->deserialize($this->definition, []));
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
        ], NormalizerOperation::Read);

        static::assertSame([
            'autofilledId' => 'test-id',
            'required' => 'test-required',
        ], $result);
    }

    public function testDeserializeFieldsIgnoresUnknownFields(): void
    {
        $result = $this->serializer->deserializeFields($this->definition, [
            'unknown' => new AttributeValue(['S' => 'test-required']),
        ], NormalizerOperation::Read);

        static::assertSame([], $result);
    }

    public function testSerializeWithEntity(): void
    {
        $entity = new NormalEntity()
            ->setAutofilledId('test-id')
            ->setName('test-name')
            ->setRequiredNullableName('test-required-nullable-name')
            ->setRequired('test-required');

        $result = $this->serializer->serialize($this->definition, $entity, NormalizerOperation::Put);

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

        $result = $this->serializer->serialize($this->definition, $entity, NormalizerOperation::Put);

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

        $result = $this->serializer->serialize($this->definition, $entity, NormalizerOperation::Put);

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

        $result = $this->serializer->serialize($this->definition, $fields, NormalizerOperation::Put);

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

        $result = $this->serializer->serialize($this->definition, $fields, NormalizerOperation::Put);

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

        $this->serializer->serialize($this->definition, $fields, NormalizerOperation::Put);
    }

    /**
     * A put names every field, an unset one as `null`, so the normalizer can fill in what the entity lacks.
     */
    public function testAPutTellsTheNormalizerAndHandsItEveryField(): void
    {
        $normalizer = new RecordingNormalizer();

        $this->serializer->serialize(
            NormalEntity::createDefinition($normalizer),
            new NormalEntity()->setAutofilledId('test-id')->setRequired('test-required'),
            NormalizerOperation::Put,
        );

        static::assertSame([
            ['normalize', NormalizerOperation::Put, ['autofilledId' => 'test-id', 'name' => null, 'requiredNullableName' => null, 'required' => 'test-required']],
        ], $normalizer->calls);
    }

    public function testAnUpdateTellsTheNormalizerAndHandsItOnlyWhatItWrites(): void
    {
        $normalizer = new RecordingNormalizer();

        $this->serializer->normalize(NormalEntity::createDefinition($normalizer), ['name' => 'test-name'], NormalizerOperation::Update);

        static::assertSame([['normalize', NormalizerOperation::Update, ['name' => 'test-name']]], $normalizer->calls);
    }

    /**
     * Both directions of a key are a key: a lookup serializes one, a cursor deserializes one.
     */
    public function testAKeyTellsTheNormalizerBothWays(): void
    {
        $normalizer = new RecordingNormalizer();
        $definition = NormalEntity::createDefinition($normalizer);

        $this->serializer->serializeKey($definition, new Key(NormalEntity::class, 'test-id'));
        $this->serializer->deserializeKey($definition, ['autofilledId' => new AttributeValue(['S' => 'test-id'])]);

        static::assertSame([
            ['normalize', NormalizerOperation::Key, ['autofilledId' => 'test-id']],
            ['denormalize', NormalizerOperation::Key, ['autofilledId' => 'test-id']],
        ], $normalizer->calls);
    }

    public function testAReadTellsTheNormalizerAndHandsItEveryField(): void
    {
        $normalizer = new RecordingNormalizer();

        $this->serializer->deserialize(NormalEntity::createDefinition($normalizer), [
            'autofilledId' => new AttributeValue(['S' => 'test-id']),
            'required' => new AttributeValue(['S' => 'test-required']),
        ]);

        static::assertSame([
            ['denormalize', NormalizerOperation::Read, ['autofilledId' => 'test-id', 'name' => null, 'requiredNullableName' => null, 'required' => 'test-required']],
        ], $normalizer->calls);
    }

    /**
     * A field the normalizer leaves out of a read is not set, so the entity keeps what it has.
     */
    public function testAReadDoesNotSetWhatTheNormalizerOmits(): void
    {
        $normalizer = new RecordingNormalizer(denormalize: static function (NormalizerContext $context): void {
            $context->omit('name');
        });
        $entity = new NormalEntity()->setName('kept');

        $this->serializer->deserialize(NormalEntity::createDefinition($normalizer), [
            'autofilledId' => new AttributeValue(['S' => 'test-id']),
            'required' => new AttributeValue(['S' => 'test-required']),
            'name' => new AttributeValue(['S' => 'stored']),
        ], $entity);

        static::assertSame('kept', $entity->getName());
        static::assertSame('test-required', $entity->getRequired());
    }

    /**
     * Omitting is no way around a required field: the entity could be left without a value for it.
     */
    public function testAReadStillNeedsARequiredFieldTheNormalizerOmits(): void
    {
        $normalizer = new RecordingNormalizer(denormalize: static function (NormalizerContext $context): void {
            $context->omit('required');
        });

        static::expectException(FieldMissingDeserializedValueException::class);
        $this->serializer->deserialize(NormalEntity::createDefinition($normalizer), [
            'autofilledId' => new AttributeValue(['S' => 'test-id']),
            'required' => new AttributeValue(['S' => 'test-required']),
        ]);
    }

    /**
     * Removing a path keeps it as `null`, which the update removes, while leaving it out does not touch it at all.
     */
    public function testNormalizeReturnsWhatTheNormalizerLeaves(): void
    {
        $normalizer = new RecordingNormalizer(static function (NormalizerContext $context): void {
            $context->set('requiredNullableName', 'added');
            $context->remove('name');
            $context->omit('required');
        });

        $fields = $this->serializer->normalize(
            NormalEntity::createDefinition($normalizer),
            ['name' => 'test-name', 'required' => 'test-required'],
            NormalizerOperation::Update,
        );

        static::assertSame(['name' => null, 'requiredNullableName' => 'added'], $fields);
    }

    public function testASerializedPutRemembersItsOperation(): void
    {
        $result = $this->serializer->serialize(
            $this->definition,
            new NormalEntity()->setAutofilledId('test-id')->setRequired('test-required'),
            NormalizerOperation::Put,
        );

        static::assertSame(NormalizerOperation::Put, $result->getOperation());
    }

    public function testDeserializeUsesDefaultValueWhenFieldMissingAndNotNullable(): void
    {
        $serializer = new StringFieldSerializer();
        $definition = new EntityDefinition(
            'normal',
            'normal',
            NormalEntity::class,
            new NormalNormalizer(),
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
            [
                'required' => new FieldDefinition('required', 'string', false, false, null, $serializer),
            ],
            new KeySchema('required'),
        );

        $this->expectException(FieldMissingSerializedValueException::class);
        $this->expectExceptionMessage('Missing required value for field');

        $this->serializer->serialize($definition, ['required' => null], NormalizerOperation::Put);
    }

    public function testSerializeWithNullNormalizerReturnsFieldsUnchanged(): void
    {
        $serializer = new StringFieldSerializer();
        $definition = new EntityDefinition(
            'normal',
            'normal',
            NormalEntity::class,
            null,
            [
                'id' => new FieldDefinition('id', 'string', false, false, null, $serializer),
            ],
            new KeySchema('id'),
        );

        $result = $this->serializer->serialize($definition, ['id' => 'x'], NormalizerOperation::Put);

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
    public function testHashKeyDerivesTheSameStringFromAnEntityAKeyAndTheRowItself(): void
    {
        $entity = new NormalEntity()->setAutofilledId('id-1')->setRequired('req');

        $fromEntity = $this->serializer->hashKey($this->definition, $entity);
        $fromKey = $this->serializer->hashKey($this->definition, new Key(NormalEntity::class, 'id-1'));
        $fromRow = $this->serializer->hashKey($this->definition, [
            'autofilledId' => new AttributeValue(['S' => 'id-1']),
            'name' => new AttributeValue(['S' => 'not part of the key']),
        ]);

        static::assertSame($fromEntity, $fromKey);
        static::assertSame($fromEntity, $fromRow);
    }

    public function testHashKeyTellsDifferentKeysApart(): void
    {
        static::assertNotSame(
            $this->serializer->hashKey($this->definition, new Key(NormalEntity::class, 'id-1')),
            $this->serializer->hashKey($this->definition, new Key(NormalEntity::class, 'id-2')),
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

        static::assertInstanceOf(Key::class, $key);
        static::assertSame('id-1', $key->hashValue);
        static::assertNull($key->rangeValue);
    }

    public function testDeserializeKeyIgnoresNonKeyFields(): void
    {
        $key = $this->serializer->deserializeKey($this->definition, [
            'autofilledId' => new AttributeValue(['S' => 'id-1']),
            'name' => new AttributeValue(['S' => 'ignored']),
        ]);

        static::assertInstanceOf(Key::class, $key);
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

        static::assertInstanceOf(Key::class, $key);
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

    public function testSerializeKeyWithAKeyReadsTheTableKeyFields(): void
    {
        $fields = $this->serializer->serializeKey($this->definition, new Key(NormalEntity::class, 'id-1'));

        static::assertEquals(['autofilledId' => new AttributeValue(['S' => 'id-1'])], $fields);
    }

    public function testSerializeKeyThrowsWhenAKeyValueIsMissingAfterSerialization(): void
    {
        // A null hash value cannot serialize to a key attribute; serializeKey must refuse it rather than
        // emit an incomplete DynamoDB key. (keyedDefinition has no normalizer, so nothing back-fills it.)
        $definition = $this->keyedDefinition();

        static::expectException(FieldMissingSerializedValueException::class);

        $this->serializer->serializeKey($definition, new Key(NormalEntity::class, null, null));
    }

    /**
     * A definition with a hash + range primary key and a GSI, for key (de)serialization.
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
