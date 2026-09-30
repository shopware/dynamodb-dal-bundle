<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Client\Write\Fixtures;

use AsyncAws\DynamoDb\ValueObject\AttributeValue;
use Shopware\DynamodbDalBundle\Definition\AttributeType;
use Shopware\DynamodbDalBundle\Definition\FieldDefinition;
use Shopware\DynamodbDalBundle\Exception\MissingAttributeValueException;
use Shopware\DynamodbDalBundle\Exception\WrongTypeException;
use Shopware\DynamodbDalBundle\Serializer\Field\AbstractFieldSerializer;

/**
 * Stores {@see Settings} as a map of its properties, so the object's structure is known to this serializer alone.
 * With `$asString`, it writes one string instead, although it declares a map, as a faulty serializer of your own may.
 *
 * @extends AbstractFieldSerializer<Settings, class-string<Settings>>
 */
final class SettingsFieldSerializer extends AbstractFieldSerializer
{
    /**
     * @param bool $asString - store the object as one string, which is no map
     */
    public function __construct(
        private readonly bool $asString = false,
    ) {
    }

    public static function supports(string $type, ?string $docblockType = null): bool
    {
        return $type === Settings::class;
    }

    public static function getAttributeType(): AttributeType
    {
        return AttributeType::Map;
    }

    public function serialize(FieldDefinition $definition, mixed $value): AttributeValue
    {
        if (!$value instanceof Settings) {
            throw new WrongTypeException($definition, Settings::class, $value);
        }

        if ($this->asString) {
            return new AttributeValue(['S' => "{$value->theme},{$value->locale}"]);
        }

        return new AttributeValue(['M' => [
            'theme' => new AttributeValue(['S' => $value->theme]),
            'locale' => new AttributeValue(['S' => $value->locale]),
        ]]);
    }

    public function deserialize(FieldDefinition $definition, AttributeValue $attributeValue): Settings
    {
        if ($this->asString) {
            [$theme, $locale] = explode(',', $attributeValue->getS() ?? throw new MissingAttributeValueException($definition, 'S'), 2) + ['', ''];

            return new Settings($theme, $locale);
        }

        $map = $attributeValue->getM();

        return new Settings(
            ($map['theme'] ?? null)?->getS() ?? throw new MissingAttributeValueException($definition, 'S'),
            ($map['locale'] ?? null)?->getS() ?? throw new MissingAttributeValueException($definition, 'S'),
        );
    }
}
