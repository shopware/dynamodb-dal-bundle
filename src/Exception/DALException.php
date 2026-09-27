<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Exception;

/**
 * Marks every DAL failure — catching this catches anything the DAL throws, whatever it was doing at the time.
 * The class says what failed; its `$entityDefinition` or `$fieldDefinition`, where it has one, says where.
 *
 * Most classes also belong to one group, which catches every failure of its kind: {@see SerializationException},
 * {@see DeserializationException} and {@see ExpressionException}.
 */
interface DALException extends \Throwable
{
}
