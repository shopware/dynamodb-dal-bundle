<?php declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use AsyncAws\DynamoDb\DynamoDbClient;
use Shopware\DynamodbDalBundle\Command\DALBaselineRequiredFieldsCommand;
use Shopware\DynamodbDalBundle\Command\DALBaselineTableSchemaCommand;
use Shopware\DynamodbDalBundle\Command\DALDefinitionCommand;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(DALDefinitionCommand::class)
        ->args([tagged_iterator('dal.definition')])
        ->tag('console.command');

    $services->set(DALBaselineRequiredFieldsCommand::class)
        ->args([tagged_iterator('dal.definition')])
        ->tag('console.command');

    $services->set(DALBaselineTableSchemaCommand::class)
        ->args([
            tagged_iterator('dal.definition'),
            service(DynamoDbClient::class),
        ])
        ->tag('console.command');
};
