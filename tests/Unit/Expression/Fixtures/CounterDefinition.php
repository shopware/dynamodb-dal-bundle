<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Expression\Fixtures;

use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\FieldDefinition;
use Shopware\DynamodbDalBundle\Definition\KeySchema;
use Shopware\DynamodbDalBundle\Serializer\AbstractNormalizer;
use Shopware\DynamodbDalBundle\Serializer\Field\FloatFieldSerializer;
use Shopware\DynamodbDalBundle\Serializer\Field\IntFieldSerializer;
use Shopware\DynamodbDalBundle\Serializer\Field\ListFieldSerializer;
use Shopware\DynamodbDalBundle\Serializer\Field\MapFieldSerializer;
use Shopware\DynamodbDalBundle\Serializer\Field\StringFieldSerializer;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\NormalEntity;

/**
 * An entity definition with the field types update actions work on, serialized for real: numbers to
 * count with, a list to append to and a map of numbers to reach into.
 *
 * `NormalEntity::createDefinition()` is all strings, which no arithmetic applies to.
 */
final class CounterDefinition
{
    /**
     * @return EntityDefinition<NormalEntity>
     */
    public static function create(?AbstractNormalizer $normalizer = null): EntityDefinition
    {
        $string = new StringFieldSerializer();
        $int = new IntFieldSerializer();

        /** @var EntityDefinition<NormalEntity> $definition */
        $definition = new EntityDefinition(
            'counter',
            'counter',
            NormalEntity::class,
            $normalizer,
            [
                'id' => new FieldDefinition('id', 'string', false, false, null, $string),
                'name' => new FieldDefinition('name', 'string', true, true, null, $string),
                'count' => new FieldDefinition('count', 'int', false, true, 0, $int),
                'ratio' => new FieldDefinition('ratio', 'float', false, true, 0.0, new FloatFieldSerializer()),
                'tags' => new FieldDefinition(
                    'tags',
                    'array',
                    false,
                    true,
                    [],
                    new ListFieldSerializer(),
                    new FieldDefinition('tags.value', 'string', true, false, null, $string),
                ),
                'meta' => new FieldDefinition(
                    'meta',
                    'array',
                    false,
                    true,
                    [],
                    new MapFieldSerializer(),
                    new FieldDefinition('meta.value', 'int', true, false, null, $int),
                ),
            ],
            new KeySchema('id'),
        );

        return $definition;
    }
}
