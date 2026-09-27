<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Exception;

/**
 * Groups the failures of a stored row that does not fit its entity: an attribute of another type than the field's
 * serializer reads, one the serializer fails on, or none where the field requires one. The bundle throws them on
 * every read of the row, and when a write reads the row back, after DynamoDB has stored the write.
 */
interface DeserializationException extends DALException
{
}
