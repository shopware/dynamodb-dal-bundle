<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Exception;

use Shopware\DynamodbDalBundle\Definition\FieldDefinition;
use Shopware\DynamodbDalBundle\Definition\FieldPath;

/**
 * A field's serializer could not turn a stored DynamoDB attribute back into a value
 */
final class FieldDeserializationException extends \RuntimeException implements DALException
{
    public readonly string $path;

    public function __construct(
        public readonly FieldDefinition $fieldDefinition,
        ?\Throwable $previous = null,
        ?string $path = null,
    ) {
        $this->path = FieldPath::locate($fieldDefinition, $path);

        parent::__construct(\sprintf(
            'Field "%s" in item "%s" could not be deserialized',
            $this->path,
            $fieldDefinition->getEntityDefinition()->getName(),
        ), 0, $previous);
    }
}
