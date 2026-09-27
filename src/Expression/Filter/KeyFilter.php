<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Expression\Filter;

use Shopware\DynamodbDalBundle\Definition\IndexSchema;
use Shopware\DynamodbDalBundle\Definition\KeySchema;
use Shopware\DynamodbDalBundle\Exception\DALException;
use Shopware\DynamodbDalBundle\Exception\InvalidKeyConditionException;
use Shopware\DynamodbDalBundle\Expression\Contract\FilterInterface;
use Shopware\DynamodbDalBundle\Expression\Filter;
use Shopware\DynamodbDalBundle\Expression\FilterCompileContext;

/**
 * A query's key condition, in the one shape DynamoDB takes: the hash key compared with {@see Filter::equals()}, and at
 * most one comparison, {@see Filter::between()} or {@see Filter::beginsWith()} of the range key. Built with {@see Filter::keyFilter()}, named after the keys as a {@see KeySchema} names them.
 *
 * It checks itself as it compiles: each key compared with values only, the hash key with `equals()`, and, as a query's
 * key condition, the field names against the key of the table or index queried.
 */
final readonly class KeyFilter implements FilterInterface
{
    /**
     * @param ComparisonFilter<Comparator::Equals> $hashKey
     * @param ComparisonFilter<Comparator>|BetweenFilter|BeginsWithFilter|null $rangeKey - `null` reads the whole partition
     */
    public function __construct(
        public ComparisonFilter $hashKey,
        public ComparisonFilter|BetweenFilter|BeginsWithFilter|null $rangeKey = null,
    ) {
    }

    /**
     * @throws InvalidKeyConditionException if DynamoDB would refuse it as a key condition
     * @throws DALException if a key field's value does not serialize for it
     */
    public function compile(FilterCompileContext $context): string
    {
        $keySchema = $context->keyCondition instanceof IndexSchema ? $context->keyCondition->keySchema : $context->keyCondition;

        // The type asks for equals() already; this holds for a caller that did not check its types
        if ($this->hashKey->comparator !== Comparator::Equals) {
            throw self::invalid($context, \sprintf('the hash key can only be compared with equals(), not with "%s"', $this->hashKey->comparator->value));
        }

        self::check($context, $this->hashKey, 'hash', $keySchema?->hashKey);
        $hashKey = $this->hashKey->compile($context);

        if ($this->rangeKey === null) {
            return $hashKey;
        }

        if ($keySchema !== null && $keySchema->rangeKey === null) {
            throw self::invalid($context, \sprintf('it checks a range key, but the key is "%s" alone', $keySchema->hashKey));
        }

        self::check($context, $this->rangeKey, 'range', $keySchema?->rangeKey);
        $rangeKey = $this->rangeKey->compile($context);
        $context->isCompound = true;

        return "{$hashKey} AND {$rangeKey}";
    }

    /**
     * Checks that a criterion compares the key with values only, and names it where the key is known.
     *
     * @param ComparisonFilter<Comparator>|BetweenFilter|BeginsWithFilter $criterion
     * @param 'hash'|'range' $role
     * @param ?string $key - the key field, `null` where the filter is not compiled as a key condition
     *
     * @throws InvalidKeyConditionException
     */
    private static function check(FilterCompileContext $context, ComparisonFilter|BetweenFilter|BeginsWithFilter $criterion, string $role, ?string $key): void
    {
        $fieldName = $criterion->fieldName;
        if ($fieldName instanceof SizeOperand) {
            throw self::refused($context, $role, $fieldName);
        }

        $values = match (true) {
            $criterion instanceof ComparisonFilter => [$criterion->value],
            $criterion instanceof BetweenFilter => [$criterion->fromValue, $criterion->toValue],
            default => [],
        };

        foreach ($values as $value) {
            if ($value instanceof SizeOperand || $value instanceof FieldOperand) {
                throw self::refused($context, $role, $value);
            }
        }

        if ($key !== null && $fieldName !== $key) {
            throw self::invalid($context, \sprintf('"%s" is not the %s key "%s"', $fieldName, $role, $key));
        }
    }

    /**
     * @param 'hash'|'range' $role
     */
    private static function refused(FilterCompileContext $context, string $role, SizeOperand|FieldOperand $operand): InvalidKeyConditionException
    {
        return self::invalid($context, \sprintf(
            'it compares the %s key with values only, not with %s',
            $role,
            $operand instanceof SizeOperand ? "the size of \"{$operand->fieldName}\"" : "the field \"{$operand->fieldName}\"",
        ));
    }

    private static function invalid(FilterCompileContext $context, string $reason): InvalidKeyConditionException
    {
        return new InvalidKeyConditionException(
            $context->definition,
            $context->keyCondition instanceof IndexSchema ? $context->keyCondition->name : null,
            $reason,
        );
    }
}
