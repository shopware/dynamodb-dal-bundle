<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Expression\Filter;

use Shopware\DynamodbDalBundle\Expression\Contract\FilterInterface;
use Shopware\DynamodbDalBundle\Expression\FilterCompileContext;

final readonly class EqualsAnyFilter implements FilterInterface
{
    /**
     * @var list<mixed>
     */
    public array $values;

    /**
     * @param list<mixed> $values - values, {@see FieldOperand} or {@see SizeOperand}
     */
    public function __construct(
        public string|SizeOperand $fieldName,
        array $values,
    ) {
        $this->values = array_values($values);
    }

    public function compile(FilterCompileContext $context): ?string
    {
        if ($this->values === []) {
            return null;
        }

        $fieldName = $this->fieldName;
        $placeholders = implode(', ', array_map(
            static fn (mixed $value): string => $context->comparand($fieldName, $value),
            $this->values,
        ));

        return \sprintf('%s IN (%s)', $context->operand($fieldName), $placeholders);
    }
}
