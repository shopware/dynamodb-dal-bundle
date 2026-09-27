<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Definition\Fixtures;

use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\FieldDefinition;
use Shopware\DynamodbDalBundle\Definition\KeySchema;
use Shopware\DynamodbDalBundle\Serializer\Field\ListFieldSerializer;
use Shopware\DynamodbDalBundle\Serializer\Field\MapFieldSerializer;
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
     * `settings` is a map and `tags` a list of strings; `deep` is a map of maps, `users` a list of maps and `matrix` a
     * list of lists of strings, so a path may descend two levels below the attribute.
     *
     * @return EntityDefinition<NormalEntity>
     */
    public static function create(): EntityDefinition
    {
        /** @var EntityDefinition<NormalEntity> $definition */
        $definition = new EntityDefinition(
            'map',
            'map',
            NormalEntity::class,
            null,
            [
                'settings' => self::map('settings'),
                'deep' => self::map('deep', self::map('value')),
                'tags' => self::list('tags'),
                'users' => self::list('users', self::map('value')),
                'matrix' => self::list('matrix', self::list('value')),
            ],
            new KeySchema('settings'),
        );

        return $definition;
    }

    /**
     * A map whose values are `$value`, or strings when none is given.
     *
     * Each call builds its own instances: `setEntityDefinition()` recurses into the value definition
     * and refuses to set it twice, so two fields cannot share one.
     */
    private static function map(string $name, ?FieldDefinition $value = null): FieldDefinition
    {
        return new FieldDefinition($name, 'array', true, true, null, new MapFieldSerializer(), $value ?? self::string());
    }

    /**
     * A list whose values are `$value`, or strings when none is given.
     */
    private static function list(string $name, ?FieldDefinition $value = null): FieldDefinition
    {
        return new FieldDefinition($name, 'array', true, true, null, new ListFieldSerializer(), $value ?? self::string());
    }

    private static function string(): FieldDefinition
    {
        return new FieldDefinition('value', 'string', false, false, null, new StringFieldSerializer());
    }
}
