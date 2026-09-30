<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Command\Baseline;

/**
 * One difference between two baselines, with what it risks and why.
 *
 * @internal
 */
final readonly class BaselineChange
{
    /**
     * @param string $entity - the logical name of the entity it changes
     * @param string $change - what changed, in Markdown, e.g. "field `note` is now required"
     * @param string $reason - why that is a risk, or why it is none, in Markdown
     */
    public function __construct(
        public string $entity,
        public ChangeRisk $risk,
        public string $change,
        public string $reason,
    ) {
    }
}
