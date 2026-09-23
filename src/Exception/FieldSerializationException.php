<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Exception;

use Shopware\DynamodbDalBundle\Definition\FieldDefinition;

/**
 * A field's serializer could not turn the value into a DynamoDB attribute
 */
final class FieldSerializationException extends \RuntimeException implements DALException
{
    public readonly string $path;

    public function __construct(
        public readonly FieldDefinition $fieldDefinition,
        ?\Throwable $previous = null,
        ?string $path = null,
    ) {
        $name = $fieldDefinition->getName();

        $this->path = match (true) {
            $path === null || $path === '' => $name,
            str_starts_with($path, '[') || str_starts_with($path, '.') => $name . $path,
            str_starts_with($path, strstr($name, '.', true) ?: $name) => $path,
            default => $name . '.' . $path,
        };

        parent::__construct(\sprintf(
            'Field "%s" in item "%s" could not be serialized',
            $this->path,
            $fieldDefinition->getEntityDefinition()->getName(),
        ), 0, $previous);
    }
}
