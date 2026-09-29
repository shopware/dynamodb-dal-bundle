<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Client\Write\Fixtures;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\FieldDefinition;
use Shopware\DynamodbDalBundle\Definition\KeySchema;
use Shopware\DynamodbDalBundle\Serializer\AbstractNormalizer;
use Shopware\DynamodbDalBundle\Serializer\Field\AbstractFieldSerializer;
use Shopware\DynamodbDalBundle\Serializer\Field\StringFieldSerializer;

/**
 * An entity holding {@see Settings}, whose entries a path reaches as `settings.<key>`, as `#[Field(valueType: 'string')]`
 * declares them.
 */
class SettingsEntity extends AbstractEntity
{
    public string $id;

    public Settings $settings;

    /**
     * @param AbstractFieldSerializer $settingsSerializer - how `settings` is stored, once the normalizer is through with it
     *
     * @return EntityDefinition<self>
     */
    public static function createDefinition(AbstractFieldSerializer $settingsSerializer, ?AbstractNormalizer $normalizer = null): EntityDefinition
    {
        $string = new StringFieldSerializer();

        /** @var EntityDefinition<self> $definition */
        $definition = new EntityDefinition(
            'settings',
            'settings',
            self::class,
            $normalizer,
            [
                'id' => new FieldDefinition('id', 'string', false, false, null, $string),
                'settings' => new FieldDefinition(
                    'settings',
                    Settings::class,
                    false,
                    false,
                    null,
                    $settingsSerializer,
                    new FieldDefinition('settings.value', 'string', true, false, null, $string),
                ),
            ],
            new KeySchema('id'),
        );

        return $definition;
    }
}
