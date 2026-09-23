<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\CompilerPass;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Attribute\Field;
use Shopware\DynamodbDalBundle\Attribute\Table;

#[Table(name: 'phpunit_test', hashKey: 'stringValue')]
class ValidEntity extends AbstractEntity
{
    #[Field]
    protected string $stringValue;

    #[Field]
    protected int $intValue;

    #[Field]
    protected float $floatValue;

    #[Field]
    protected bool $boolValue;

    #[Field]
    protected ?\DateTimeImmutable $dateTimeValue = null;

    #[Field]
    protected ?string $nullableValueWithDefault = 'sdf';

    #[Field]
    protected ?string $nullableValue;
}
