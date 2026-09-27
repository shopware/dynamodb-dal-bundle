<?php declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Shopware\DynamodbDalBundle\Client\ReaderClient;
use Shopware\DynamodbDalBundle\Client\WriterClient;
use Shopware\DynamodbDalBundle\Profiler\CallStampingHttpClient;
use Shopware\DynamodbDalBundle\Profiler\DalCallTracer;
use Shopware\DynamodbDalBundle\Profiler\DynamoDbDataCollector;
use Shopware\DynamodbDalBundle\Profiler\TraceableReaderClient;
use Shopware\DynamodbDalBundle\Profiler\TraceableWriterClient;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Stopwatch\Stopwatch;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(DalCallTracer::class)
        ->args([
            service('.debug.aws.base-client')->nullOnInvalid(),
            service('profiler.is_disabled_state_checker')->nullOnInvalid(),
            service(Stopwatch::class)->nullOnInvalid(),
        ])
        ->tag('kernel.reset', ['method' => 'reset']);

    // Priority 260 puts collect() before HttpClientDataCollector's (250), which empties the traced client the requests
    // sent outside the DAL are read off
    $services->set(DynamoDbDataCollector::class)
        ->args([service(DalCallTracer::class)])
        ->tag('data_collector', ['id' => 'dynamo_db', 'priority' => 260]);

    $services->set(TraceableReaderClient::class)
        ->decorate(ReaderClient::class)
        ->args([service('.inner'), service(DalCallTracer::class)]);

    $services->set(TraceableWriterClient::class)
        ->decorate(WriterClient::class)
        ->args([service('.inner'), service(DalCallTracer::class)]);

    // Symfony orders decorators low-priority-outer / high-priority-inner, and HttpClientPass wraps
    // every `http_client.client` in a TraceableHttpClient at priority 100, so priority 0 stays outside
    // it and the stamped options land in the trace
    $services->set(CallStampingHttpClient::class)
        ->decorate('aws.base-client', null, 0, ContainerInterface::IGNORE_ON_INVALID_REFERENCE)
        ->args([service('.inner'), service(DalCallTracer::class)]);
};
