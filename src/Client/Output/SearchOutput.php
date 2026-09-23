<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Client\Output;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Client\Cursor\Cursor;
use Shopware\DynamodbDalBundle\Client\Input\QueryInput;
use Shopware\DynamodbDalBundle\Client\Input\ScanInput;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * @template Entity of AbstractEntity
 *
 * @extends ReadOutput<Entity>
 */
#[Exclude]
final class SearchOutput extends ReadOutput
{
    /**
     * @param \Generator<int, Entity> $source
     * @param EntityDefinition<Entity> $definition
     */
    public function __construct(
        \Generator $source,
        private readonly EntityDefinition $definition,
        private readonly ScanInput|QueryInput $search,
    ) {
        parent::__construct($source);
    }

    /**
     * Returns all items requested until $limit is reached. If items are left, a {@see Cursor} is returned,
     * allowing to continue fetching items for the next page.
     *
     * @return Page<Entity>
     */
    public function page(): Page
    {
        $limit = $this->search->limit;
        $index = $this->search instanceof QueryInput ? $this->search->index : null;

        if ($limit === null) {
            $items = $this->toArray();

            return new Page($items, null);
        }

        $limit = max(1, $limit);

        $items = $this->take($limit + 1);
        $hasNextPage = \count($items) > $limit;
        $visible = \array_slice($items, 0, $limit);

        $nextCursor = $hasNextPage && $visible !== []
            ? Cursor::from($this->definition, $visible[array_key_last($visible)], $index)
            : null;

        return new Page($visible, $nextCursor);
    }
}
