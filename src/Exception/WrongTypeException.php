<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Exception;

use Shopware\DynamodbDalBundle\Definition\FieldDefinition;

/**
 * A value handed to a field's serializer is not of the type that serializer works on.
 */
final class WrongTypeException extends \RuntimeException implements DALException
{
    public readonly string $actualType;

    public function __construct(
        public readonly FieldDefinition $fieldDefinition,
        public readonly string $expectedType,
        mixed $actualValue,
        ?\Throwable $previous = null,
    ) {
        $this->actualType = \is_object($actualValue) ? $actualValue::class : \gettype($actualValue);

        parent::__construct(\sprintf(
            'Expected type "%s" for field "%s" in item "%s", got "%s"',
            $expectedType,
            $fieldDefinition->getName(),
            $fieldDefinition->getEntityDefinition()->getName(),
            $this->actualType,
        ), 0, $previous);
    }
}
