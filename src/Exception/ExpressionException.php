<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Exception;

/**
 * Groups the failures of a filter, condition, key condition or update that cannot be sent as it is: it names a
 * field the entity does not have, uses a field as a type it is not stored as, compares with `null`, checks or writes
 * nothing, or is one DynamoDB would refuse. The bundle throws them before it sends the request.
 *
 * A value in the expression that does not fit its field throws a {@see SerializationException} instead, as it would
 * in a put.
 */
interface ExpressionException extends DALException
{
}
