<?php declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Shopware\DynamodbDalBundle\Profiler\CallerStampingHttpClient;
use Shopware\DynamodbDalBundle\Profiler\DynamoDbDataCollector;
use Shopware\DynamodbDalBundle\Profiler\TraceableSerializer;
use Shopware\DynamodbDalBundle\Serializer\Serializer;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Stopwatch\Stopwatch;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    // Priority 260 puts collect() before HttpClientDataCollector (250)
    $services->set(DynamoDbDataCollector::class)
        ->args([service('.debug.aws.base-client')->nullOnInvalid()])
        ->tag('data_collector', ['id' => 'dynamo_db', 'priority' => 260]);

    $services->set(TraceableSerializer::class)
        ->decorate(Serializer::class)
        ->args([
            service('.inner'),
            service(DynamoDbDataCollector::class),
            service(Stopwatch::class)->nullOnInvalid(),
        ]);

    // Symfony orders decorators low-priority-outer / high-priority-inner, and HttpClientPass wraps
    // every `http_client.client` in a TraceableHttpClient at priority 100
    $services->set(CallerStampingHttpClient::class)
        ->decorate('aws.base-client', null, 0, ContainerInterface::IGNORE_ON_INVALID_REFERENCE)
        ->args([service('.inner')]);
};
