<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Exception;

/**
 * Groups the failures of a value that does not fit its field: one of the wrong type, one the field's serializer
 * fails on, or none where the field requires one. The bundle throws them before it sends the request, for an entity,
 * a key, or a value in a filter, condition or update alike, and a field serializer may throw one on a read, such as
 * for stored JSON that holds no array. Its `$fieldDefinition` says which field, except for an
 * {@see UnknownFieldException}, whose `$field` names one the entity does not declare.
 */
interface SerializationException extends DALException
{
}
