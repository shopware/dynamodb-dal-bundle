<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Exception;

use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\FieldDefinition;

/**
 * Base of every DAL failure. It carries the machine-readable {@see getErrorCode()} and the
 * {@see getParameters()} the message was interpolated from, so a caller can branch on the cause
 * without parsing the message, and an application can re-render it in its own terms.
 */
abstract class DALException extends \RuntimeException
{
    public readonly string $errorCode;

    /**
     * @var array<string, mixed>
     */
    protected array $parameters = [];

    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(
        string $message,
        string $code,
        array $parameters = [],
        protected ?FieldDefinition $fieldDefinition = null,
        protected ?EntityDefinition $entityDefinition = null,
        ?\Throwable $previous = null,
    ) {
        $this->entityDefinition ??= $fieldDefinition?->getEntityDefinition();
        /** @phpstan-ignore-next-line arrayFilter.strict -- loose check is desired here */
        $contextParameters = array_filter([
            'entity' => $this->entityDefinition?->getName(),
            'field' => $this->fieldDefinition?->getName(),
        ]);

        $this->parameters = array_merge($contextParameters, $parameters);
        $this->errorCode = $code;

        parent::__construct($this->parse($message, $this->parameters), 0, $previous);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    public function getParameter(string $key): mixed
    {
        return $this->parameters[$key] ?? null;
    }

    public function is(string ...$errorCodes): bool
    {
        return \in_array($this->errorCode, $errorCodes, true);
    }

    public function getEntityDefinition(): ?EntityDefinition
    {
        return $this->entityDefinition;
    }

    public function getFieldDefinition(): ?FieldDefinition
    {
        return $this->fieldDefinition;
    }

    /**
     * Replaces every `{name}` placeholder in the message with the matching scalar parameter.
     *
     * @param array<string, mixed> $parameters
     */
    protected function parse(string $message, array $parameters = []): string
    {
        $replacements = [];

        foreach ($parameters as $key => $value) {
            if (\is_array($value)) {
                continue;
            }

            $formattedKey = preg_replace('/[^a-z0-9_]/i', '', $key);
            $replacements[\sprintf('{%s}', $formattedKey)] = (string) $value;
        }

        return str_replace(array_keys($replacements), array_values($replacements), $message);
    }
}
