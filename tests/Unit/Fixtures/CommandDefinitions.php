<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Fixtures;

use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\FieldDefinition;
use Shopware\DynamodbDalBundle\Definition\IndexSchema;
use Shopware\DynamodbDalBundle\Definition\KeySchema;
use Shopware\DynamodbDalBundle\Serializer\Field\IntFieldSerializer;
use Shopware\DynamodbDalBundle\Serializer\Field\ListFieldSerializer;
use Shopware\DynamodbDalBundle\Serializer\Field\MapFieldSerializer;
use Shopware\DynamodbDalBundle\Serializer\Field\StringFieldSerializer;

/**
 * The definitions the command tests print, dump and validate, built by hand.
 */
final class CommandDefinitions
{
    /**
     * A composite key, two indexes, a nullable field, defaulted ones and a list and map, one nested in the other.
     *
     * @return EntityDefinition<OrderEntity>
     */
    public static function order(): EntityDefinition
    {
        $stringSerializer = new StringFieldSerializer();

        /** @var FieldDefinition<OrderEntity> $tenantId */
        $tenantId = new FieldDefinition('tenantId', 'string', false, false, null, $stringSerializer);
        /** @var FieldDefinition<OrderEntity> $externalId */
        $externalId = new FieldDefinition('externalId', 'string', false, false, null, $stringSerializer);
        /** @var FieldDefinition<OrderEntity> $reference */
        $reference = new FieldDefinition('reference', 'string', false, false, null, $stringSerializer);
        /** @var FieldDefinition<OrderEntity> $revision */
        $revision = new FieldDefinition('revision', 'int', false, true, 0, new IntFieldSerializer());
        /** @var FieldDefinition<OrderEntity> $note */
        $note = new FieldDefinition('note', 'string', true, false, null, $stringSerializer);
        /** @var FieldDefinition<OrderEntity> $tags */
        $tags = new FieldDefinition(
            'tags',
            'array',
            true,
            true,
            [],
            new ListFieldSerializer(),
            new FieldDefinition('tags.value', 'string', true, false, null, $stringSerializer),
        );

        /** @var FieldDefinition<OrderEntity> $groups */
        $groups = new FieldDefinition(
            'groups',
            'array',
            true,
            false,
            null,
            new MapFieldSerializer(),
            new FieldDefinition(
                'groups.value',
                'array',
                true,
                false,
                null,
                new ListFieldSerializer(),
                new FieldDefinition('groups.value.value', 'string', false, false, null, $stringSerializer),
            ),
        );

        return new EntityDefinition(
            'order',
            'phpunit-order',
            OrderEntity::class,
            null,
            [
                'tenantId' => $tenantId,
                'externalId' => $externalId,
                'reference' => $reference,
                'revision' => $revision,
                'note' => $note,
                'tags' => $tags,
                'groups' => $groups,
            ],
            new KeySchema('tenantId', 'externalId'),
            [
                'referenceIndex' => new IndexSchema('referenceIndex', 'reference', 'revision'),
                // Keyed like a local secondary index, on the table's hash key
                'revisionIndex' => new IndexSchema('revisionIndex', 'tenantId', 'revision'),
            ],
        );
    }

    /**
     * A hash key alone, and neither an index nor a normalizer.
     *
     * @return EntityDefinition<CustomerEntity>
     */
    public static function customer(): EntityDefinition
    {
        /** @var FieldDefinition<CustomerEntity> $tenantId */
        $tenantId = new FieldDefinition('tenantId', 'string', false, false, null, new StringFieldSerializer());

        return new EntityDefinition(
            'config',
            'phpunit-customer',
            CustomerEntity::class,
            null,
            ['tenantId' => $tenantId],
            new KeySchema('tenantId'),
        );
    }
}
