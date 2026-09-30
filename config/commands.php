<?php declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use AsyncAws\DynamoDb\DynamoDbClient;
use Shopware\DynamodbDalBundle\Command\DALBaselineDumpCommand;
use Shopware\DynamodbDalBundle\Command\DALDefinitionInspectCommand;
use Shopware\DynamodbDalBundle\Command\DALDefinitionValidateCommand;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(DALDefinitionInspectCommand::class)
        ->args([tagged_iterator('dal.definition')])
        ->tag('console.command');

    $services->set(DALDefinitionValidateCommand::class)
        ->args([
            tagged_iterator('dal.definition'),
            service(DynamoDbClient::class),
        ])
        ->tag('console.command');

    $services->set(DALBaselineDumpCommand::class)
        ->args([tagged_iterator('dal.definition')])
        ->tag('console.command');
};
