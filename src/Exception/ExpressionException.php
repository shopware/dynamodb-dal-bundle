<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Exception;

use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\FieldDefinition;

class ExpressionException extends DALException
{
    public const string UNKNOWN_FIELD = 'DAL__EXPRESSION__UNKNOWN_FIELD';
    public const string NULL_FILTER_VALUE = 'DAL__EXPRESSION__NULL_FILTER_VALUE';

    public static function unknownField(EntityDefinition $entityDefinition, string $name): self
    {
        return new self(
            message: 'Unknown field "{field}" in item "{entity}" cannot be used in criteria',
            code: self::UNKNOWN_FIELD,
            entityDefinition: $entityDefinition,
            parameters: [
                'field' => $name,
            ],
        );
    }

    public static function nullFilterValue(FieldDefinition $fieldDefinition): self
    {
        return new self(
            message: 'Filter value for field "{field}" in item "{entity}" must not be null. Use exists/not(exists) to filter for absence of an attribute.',
            code: self::NULL_FILTER_VALUE,
            fieldDefinition: $fieldDefinition,
        );
    }
}
