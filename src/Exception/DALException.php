<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Exception;

/**
 * Marks every DAL failure — catching this catches anything the DAL throws, whatever it was doing at
 * the time. What went wrong is the class itself; where it went wrong is on the class, as the
 * definition the failure happened on.
 */
interface DALException extends \Throwable
{
}
