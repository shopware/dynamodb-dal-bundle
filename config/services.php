<?php declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use AsyncAws\DynamoDb\DynamoDbClient;
use Shopware\DynamodbDalBundle\Client\Client;
use Shopware\DynamodbDalBundle\Client\ReaderClient;
use Shopware\DynamodbDalBundle\Client\WriterClient;
use Shopware\DynamodbDalBundle\Expression\ExpressionCompiler;
use Shopware\DynamodbDalBundle\Definition\EntityDefinitionRegistry;
use Shopware\DynamodbDalBundle\Serializer\Field\AbstractFieldSerializer;
use Shopware\DynamodbDalBundle\Serializer\Field\BackedEnumFieldSerializer;
use Shopware\DynamodbDalBundle\Serializer\Field\BoolFieldSerializer;
use Shopware\DynamodbDalBundle\Serializer\Field\DateTimeFieldSerializer;
use Shopware\DynamodbDalBundle\Serializer\Field\FloatFieldSerializer;
use Shopware\DynamodbDalBundle\Serializer\Field\IntFieldSerializer;
use Shopware\DynamodbDalBundle\Serializer\Field\JsonFieldSerializer;
use Shopware\DynamodbDalBundle\Serializer\Field\ListFieldSerializer;
use Shopware\DynamodbDalBundle\Serializer\Field\MapFieldSerializer;
use Shopware\DynamodbDalBundle\Serializer\Field\StringFieldSerializer;
use Shopware\DynamodbDalBundle\Serializer\Field\UidFieldSerializer;
use Shopware\DynamodbDalBundle\Serializer\Serializer;
use Symfony\Component\Uid\AbstractUid;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(Serializer::class);

    $services->set(ExpressionCompiler::class)
        ->tag('kernel.reset', ['method' => 'reset']);

    $services->set(ReaderClient::class)
        ->args([
            service(DynamoDbClient::class),
            service(Serializer::class),
            service(ExpressionCompiler::class),
            service(EntityDefinitionRegistry::class),
        ]);

    $services->set(WriterClient::class)
        ->args([
            service(DynamoDbClient::class),
            service(Serializer::class),
            service(ExpressionCompiler::class),
            service(EntityDefinitionRegistry::class),
            service(ReaderClient::class),
        ]);

    $services->set(Client::class)
        ->args([
            service(ReaderClient::class),
            service(WriterClient::class),
        ]);

    // A property takes the first serializer, by tag priority, that claims its type.
    // The application's own serializers have the default priority 0, so they take precedence over these
    foreach ([
        // symfony/uid is optional, so UidFieldSerializer is only registered when it is installed
        ...(class_exists(AbstractUid::class) ? [UidFieldSerializer::class] : []),
        DateTimeFieldSerializer::class,
        BackedEnumFieldSerializer::class,
        StringFieldSerializer::class,
        IntFieldSerializer::class,
        FloatFieldSerializer::class,
        BoolFieldSerializer::class,
        ListFieldSerializer::class,
        MapFieldSerializer::class,
    ] as $fieldSerializer) {
        $services->set($fieldSerializer)
            ->tag(AbstractFieldSerializer::class, ['priority' => -100]);
    }

    // JsonFieldSerializer accepts any remaining array or JsonSerializable, so it comes last.
    $services->set(JsonFieldSerializer::class)
        ->tag(AbstractFieldSerializer::class, ['priority' => -500]);
};
