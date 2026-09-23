<?php declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use AsyncAws\DynamoDb\DynamoDbClient;
use Shopware\DynamodbDalBundle\Client\Client;
use Shopware\DynamodbDalBundle\Client\Cursor\CursorNormalizer;
use Shopware\DynamodbDalBundle\Client\ReaderClient;
use Shopware\DynamodbDalBundle\Client\WriterClient;
use Shopware\DynamodbDalBundle\Criteria\ExpressionCompiler;
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
        ]);

    $services->set(Client::class)
        ->args([
            service(ReaderClient::class),
            service(WriterClient::class),
        ]);

    $services->set('.shopware_dynamodb_dal.lazy.serializer', Serializer::class)
        ->factory('current')
        ->args([[service(Serializer::class)]])
        ->lazy();

    $services->set('.shopware_dynamodb_dal.lazy.definition_registry', EntityDefinitionRegistry::class)
        ->factory('current')
        ->args([[service(EntityDefinitionRegistry::class)]])
        ->lazy();

    $services->set(CursorNormalizer::class)
        ->args([
            service('.shopware_dynamodb_dal.lazy.serializer'),
            service('.shopware_dynamodb_dal.lazy.definition_registry'),
        ])
        ->tag('serializer.normalizer');

    // Order matters: the compiler pass takes the first serializer that claims a property's type, so
    // the narrow ones come before JsonFieldSerializer, which accepts any remaining array or
    // JsonSerializable.
    foreach ([
        UidFieldSerializer::class,
        DateTimeFieldSerializer::class,
        BackedEnumFieldSerializer::class,
        StringFieldSerializer::class,
        IntFieldSerializer::class,
        FloatFieldSerializer::class,
        BoolFieldSerializer::class,
        ListFieldSerializer::class,
        MapFieldSerializer::class,
        JsonFieldSerializer::class,
    ] as $fieldSerializer) {
        $services->set($fieldSerializer)
            ->tag(AbstractFieldSerializer::class);
    }
};
