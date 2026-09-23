<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Definition\Fixtures;

use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\FieldDefinition;
use Shopware\DynamodbDalBundle\Definition\KeySchema;
use Shopware\DynamodbDalBundle\Serializer\Field\AbstractFieldSerializer;
use Shopware\DynamodbDalBundle\Serializer\Field\StringFieldSerializer;
use Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures\NormalEntity;

/**
 * An entity definition with collection fields, so a test can build a path that actually descends.
 *
 * `NormalEntity::createDefinition()` is all scalars, which only ever yields a one-segment path.
 */
final class MapDefinition
{
    /**
     * `settings` and `tags` hold strings; `deep`, `users` and `matrix` hold collections of strings, so
     * a path may descend two levels below the attribute.
     *
     * @return EntityDefinition<NormalEntity>
     */
    public static function create(AbstractFieldSerializer $serializer = new StringFieldSerializer()): EntityDefinition
    {
        /** @var EntityDefinition<NormalEntity> $definition */
        $definition = new EntityDefinition(
            'map',
            'map',
            NormalEntity::class,
            null,
            [
                'settings' => self::collection('settings', $serializer),
                'deep' => self::collection('deep', $serializer, self::collection('value', $serializer)),
                'tags' => self::collection('tags', $serializer),
                'users' => self::collection('users', $serializer, self::collection('value', $serializer)),
                'matrix' => self::collection('matrix', $serializer, self::collection('value', $serializer)),
            ],
            new KeySchema('settings'),
        );

        foreach ($definition->getFieldDefinitions() as $fieldDefinition) {
            $fieldDefinition->setEntityDefinition($definition);
        }

        return $definition;
    }

    /**
     * A collection whose values are `$value`, or strings when none is given.
     *
     * Each call builds its own instances: `setEntityDefinition()` recurses into the value definition
     * and refuses to set it twice, so two fields cannot share one.
     */
    private static function collection(
        string $name,
        AbstractFieldSerializer $serializer,
        ?FieldDefinition $value = null,
    ): FieldDefinition {
        return new FieldDefinition(
            $name,
            'array',
            true,
            true,
            null,
            $serializer,
            $value ?? new FieldDefinition('value', 'string', false, false, null, $serializer),
        );
    }
}
