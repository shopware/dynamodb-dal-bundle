<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Serializer\Fixtures;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\FieldDefinition;
use Shopware\DynamodbDalBundle\Definition\KeySchema;
use Shopware\DynamodbDalBundle\Serializer\AbstractNormalizer;
use Shopware\DynamodbDalBundle\Serializer\Field\AbstractFieldSerializer;
use Shopware\DynamodbDalBundle\Serializer\Field\StringFieldSerializer;

class NormalEntity extends AbstractEntity
{
    protected string $autofilledId;

    protected ?string $name = null;

    protected ?string $requiredNullableName;

    protected string $required;

    public function getAutofilledId(): string
    {
        return $this->autofilledId;
    }

    public function setAutofilledId(string $autofilledId): self
    {
        $this->autofilledId = $autofilledId;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getRequiredNullableName(): ?string
    {
        return $this->requiredNullableName;
    }

    public function setRequiredNullableName(?string $requiredNullableName): self
    {
        $this->requiredNullableName = $requiredNullableName;

        return $this;
    }

    public function getRequired(): string
    {
        return $this->required;
    }

    public function setRequired(string $required): self
    {
        $this->required = $required;

        return $this;
    }

    /**
     * @return EntityDefinition<self>
     */
    public static function createDefinition(
        AbstractNormalizer $normalizer = new NormalNormalizer(),
        AbstractFieldSerializer $fieldSerializer = new StringFieldSerializer(),
    ): EntityDefinition {
        /** @var EntityDefinition<self> $definition */
        $definition = new EntityDefinition(
            'normal',
            'normal',
            self::class,
            $normalizer,
            [
                'autofilledId' => new FieldDefinition(
                    'autofilledId',
                    'string',
                    false,
                    false,
                    null,
                    $fieldSerializer,
                ),
                'name' => new FieldDefinition(
                    'name',
                    'string',
                    true,
                    true,
                    null,
                    $fieldSerializer,
                ),
                'requiredNullableName' => new FieldDefinition(
                    'requiredNullableName',
                    'string',
                    true,
                    false,
                    null,
                    $fieldSerializer,
                ),
                'required' => new FieldDefinition(
                    'required',
                    'string',
                    false,
                    false,
                    null,
                    $fieldSerializer,
                ),
            ],
            new KeySchema('autofilledId'),
        );

        return $definition;
    }
}
