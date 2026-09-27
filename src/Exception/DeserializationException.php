<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Exception;

/**
 * Groups the failures of a stored row that does not fit its entity: an attribute of another type than the field's
 * serializer reads, one the serializer fails on, or none where the field requires one. The bundle throws them on
 * every read of the row. A write that brings its entity up to date after DynamoDB has stored it throws an
 * {@see EntityOutOfSyncException} instead, which is one too.
 */
interface DeserializationException extends DALException
{
}
