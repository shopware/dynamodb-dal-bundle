<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Expression\Filter;

use Shopware\DynamodbDalBundle\Expression\Contract\FilterInterface;
use Shopware\DynamodbDalBundle\Expression\ExpressionCompileContext;

final readonly class EqualsAnyFilter implements FilterInterface
{
    /**
     * @var list<mixed>
     */
    public array $values;

    /**
     * @param list<mixed> $values
     */
    public function __construct(
        public string $fieldName,
        array $values,
    ) {
        $this->values = array_values($values);
    }

    public function compile(ExpressionCompileContext $context): ?string
    {
        if ($this->values === []) {
            return null;
        }

        $fieldName = $this->fieldName;
        $placeholders = implode(', ', array_map(
            static fn (mixed $value): string => $context->value($fieldName, $value),
            $this->values,
        ));

        return "{$context->path($fieldName)} IN ({$placeholders})";
    }
}
