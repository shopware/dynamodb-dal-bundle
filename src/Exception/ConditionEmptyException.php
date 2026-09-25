<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Exception;

use Shopware\DynamodbDalBundle\Definition\EntityDefinition;

/**
 * A condition that checks nothing: an empty `Filter::and()`, or filters that all compiled to nothing, such as
 * `Filter::equalsAny()` without values. As a write's condition it would let the write through unconditionally,
 * and a query cannot go out without a key condition. To write without a condition, pass none.
 */
final class ConditionEmptyException extends \RuntimeException implements DALException
{
    public function __construct(public readonly EntityDefinition $entityDefinition)
    {
        parent::__construct(\sprintf('Condition on item "%s" checks nothing', $entityDefinition->getName()));
    }
}
