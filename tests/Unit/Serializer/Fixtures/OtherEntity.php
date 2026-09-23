<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\FieldDefinition;
use Shopware\DynamodbDalBundle\Definition\KeySchema;
use Shopware\DynamodbDalBundle\Serializer\Field\AbstractFieldSerializer;
use Shopware\DynamodbDalBundle\Serializer\Field\StringFieldSerializer;

/**
 * A second entity, in its own table — the counterpart {@see NormalEntity} needs wherever a read spans
 * several tables and results are told apart by their entity class.
 *
 */
class OtherEntity extends AbstractEntity
{
    protected string $otherId;

    public function getOtherId(): string
    {
        return $this->otherId;
    }

    public function setOtherId(string $otherId): self
    {
        $this->otherId = $otherId;

        return $this;
    }

    /**
     * @return EntityDefinition<self>
     */
    public static function createDefinition(
        AbstractFieldSerializer $fieldSerializer = new StringFieldSerializer(),
    ): EntityDefinition {
        /** @var EntityDefinition<self> $definition */
        $definition = new EntityDefinition(
            'other',
            'other-physical',
            self::class,
            null,
            /** @phpstan-ignore-next-line -- FieldDefinition generic is missing */
            [
                'otherId' => new FieldDefinition(
                    'otherId',
                    'string',
                    false,
                    false,
                    null,
                    $fieldSerializer,
                ),
            ],
            new KeySchema('otherId'),
        );

        foreach ($definition->getFieldDefinitions() as $fieldDefinition) {
            $fieldDefinition->setEntityDefinition($definition);
        }

        return $definition;
    }
}
