<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Command\Baseline;

use Shopware\DynamodbDalBundle\Definition\FieldDefinition;

/**
 * @internal
 */
final readonly class FieldBaseline
{
    /**
     * @param string $type - the attribute type the field is stored as, e.g. `M`
     * @param bool $required - neither nullable nor defaulted, so a stored row that lacks it fails to read
     * @param list<string> $valueTypes - the attribute types of the values of a list or map, the outermost first
     */
    public function __construct(
        public string $type,
        public bool $required,
        public array $valueTypes = [],
    ) {
    }

    public static function fromDefinition(FieldDefinition $field): self
    {
        $valueTypes = [];
        for ($value = $field->getValueFieldDefinition(); $value !== null; $value = $value->getValueFieldDefinition()) {
            $valueTypes[] = $value->getAttributeType()->value;
        }

        return new self(
            $field->getAttributeType()->value,
            !$field->allowsNull() && !$field->hasDefaultValue(),
            $valueTypes,
        );
    }

    /**
     * The type of every level, e.g. `M<L<S>>` for a map of lists of strings.
     */
    public function describeType(): string
    {
        return array_reduce(
            array_reverse([$this->type, ...$this->valueTypes]),
            static fn (?string $inner, string $type): string => $inner === null ? $type : "{$type}<{$inner}>",
        ) ?? $this->type;
    }
}
