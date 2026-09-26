<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Exception;

/**
 * Marks every DAL failure — catching this catches anything the DAL throws, whatever it was doing at the time.
 * The class says what failed; its `$entityDefinition` or `$fieldDefinition`, where it has one, says where.
 */
interface DALException extends \Throwable
{
}
