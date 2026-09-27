<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Exception;

use Shopware\DynamodbDalBundle\Definition\EntityDefinition;

/**
 * A query's key condition that DynamoDB would refuse. Built with `Filter::keyFilter()`, it has DynamoDB's shape
 * already, but its criteria may name a field that is not the hash or range key of the table or index queried, or
 * compare a key with a size or another field instead of a value.
 */
final class InvalidKeyConditionException extends \InvalidArgumentException implements ExpressionException
{
    /**
     * @param ?string $index - the index queried, `null` for the table
     */
    public function __construct(
        public readonly EntityDefinition $entityDefinition,
        public readonly ?string $index,
        public readonly string $reason,
    ) {
        parent::__construct(\sprintf(
            'Invalid key condition on %s of item "%s": %s.',
            $index !== null ? \sprintf('index "%s"', $index) : 'the table',
            $entityDefinition->getName(),
            $reason,
        ));
    }
}
