<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Expression\Filter;

use Shopware\DynamodbDalBundle\Expression\Contract\ExpressionInterface;
use Shopware\DynamodbDalBundle\Expression\ExpressionCompileContext;

class EqualsAnyFilter implements ExpressionInterface
{
    /**
     * @var list<mixed>
     */
    public readonly array $values;

    /**
     * @param list<mixed> $values
     */
    public function __construct(
        public readonly string $fieldName,
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
            static fn (mixed $value): string => $context->placeholder($fieldName, $value),
            $this->values,
        ));

        return "{$context->attribute($fieldName)} IN ({$placeholders})";
    }
}
