<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Exception;

/**
 * No entity definition is registered for the requested table or entity class.
 */
class EntityDefinitionException extends DALException
{
    public const string UNKNOWN_TABLE = 'DAL__ENTITY_DEFINITION__UNKNOWN_TABLE';

    public static function unknownTable(string $table): self
    {
        return new self(
            message: 'No entity definition is registered for table "{table}".',
            code: self::UNKNOWN_TABLE,
            parameters: [
                'table' => $table,
            ],
        );
    }

    public static function unknownTableByEntity(string $class): self
    {
        return new self(
            message: 'No entity definition is registered for entity class "{entityClass}".',
            code: self::UNKNOWN_TABLE,
            parameters: [
                'entityClass' => $class,
            ],
        );
    }
}
