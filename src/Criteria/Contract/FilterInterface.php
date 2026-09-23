<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Criteria\Contract;

use Shopware\DynamodbDalBundle\Criteria\ExpressionCompileContext;

interface FilterInterface
{
    /**
     * Compile this filter into a DynamoDB FilterExpression fragment.
     *
     * Returning `null` means "no contribution" — the filter is silently skipped by the
     * compiler and any logical group that contains it. Implementations MUST NOT register
     * attribute names or values on the context when they return `null`, so empty
     * children don't pollute the final {@see \AsyncAws\DynamoDb\Result\ScanOutput} request.
     */
    public function compile(ExpressionCompileContext $context): ?string;
}
