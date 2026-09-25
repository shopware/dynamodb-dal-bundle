<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Client\Output;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Client\Cursor;
use Shopware\DynamodbDalBundle\Client\Input\QueryInput;
use Shopware\DynamodbDalBundle\Client\Input\ScanInput;
use Shopware\DynamodbDalBundle\Client\ReaderClient;
use Shopware\DynamodbDalBundle\Exception\DALException;
use Shopware\DynamodbDalBundle\Exception\InvalidCursorException;
use Shopware\DynamodbDalBundle\Exception\UnknownEntityDefinitionException;
use AsyncAws\Core\Exception\Exception as AsyncAwsException;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;

/**
 * Not `final`, so a test can double it.
 *
 * @template Entity of AbstractEntity
 *
 * @extends ReadOutput<Entity, array<string, AttributeValue>|int>
 *
 * @final
 */
class SearchOutput extends ReadOutput
{
    private readonly ?int $limit;

    /**
     * @internal
     *
     * @param \Generator<array<string, AttributeValue>|int, Entity> $source - each entity keyed by its raw start key, as {@see ReaderClient::search()} yields them. A source keyed by position (a test double) streams all the same, but its tokens are refused when used
     * @param ScanInput<Entity>|QueryInput<Entity> $search
     */
    public function __construct(
        \Generator $source,
        private readonly ScanInput|QueryInput $search,
    ) {
        $this->limit = $this->search->limit !== null ? max(1, $this->search->limit) : null;
        parent::__construct($source);
    }

    /**
     * @throws \LogicException if this output has already been read
     * @throws UnknownEntityDefinitionException
     * @throws InvalidCursorException
     * @throws DALException if the query does not compile, or an item does not deserialize
     * @throws AsyncAwsException if a request to DynamoDB fails
     *
     * @return \Generator<int, Entity>
     */
    public function getIterator(): \Generator
    {
        foreach (parent::getIterator() as $position => $entity) {
            yield $position => $entity;

            if ($this->limit !== null && $position + 1 === $this->limit) {
                return;
            }
        }
    }

    /**
     * Returns all items requested until $limit is reached, with tokens for the neighbouring pages.
     *
     * Going back is the same query read in reverse from the first item, so it needs no history of the pages visited before.
     *
     * @throws \LogicException if this output has already been read
     * @throws UnknownEntityDefinitionException
     * @throws InvalidCursorException also if a key attribute of a boundary item is not valid UTF-8
     * @throws DALException if the query does not compile, or an item does not deserialize
     * @throws AsyncAwsException if a request to DynamoDB fails
     *
     * @return Page<Entity>
     */
    public function page(): Page
    {
        $cursor = $this->search->cursor !== null ? Cursor::decode($this->search->cursor) : null;
        $backward = $cursor !== null && $cursor->backward;

        $items = [];
        $keys = [];
        $hasMore = false;
        foreach ($this->stream() as $key => $entity) {
            if ($this->limit !== null && \count($items) >= $this->limit) {
                $hasMore = true;

                break;
            }

            $items[] = $entity;
            $keys[] = \is_array($key) ? $key : [];
        }

        // A backward read runs from the current page towards the start; restore the query's order.
        if ($backward) {
            $items = array_reverse($items);
            $keys = array_reverse($keys);
        }

        // Having come back from a later page, there always is a next one — and a previous one only if more
        // items were left before; reading forward it is the other way around. A scan has no order to reverse,
        // so it never offers a previous page.
        $hasNext = $backward || $hasMore;
        $hasPrevious = $this->search instanceof QueryInput && ($backward ? $hasMore : $cursor !== null);

        return new Page(
            $items,
            $hasNext && $keys !== [] ? new Cursor($keys[array_key_last($keys)])->encode() : null,
            $hasPrevious && $keys !== [] ? new Cursor($keys[0], backward: true)->encode() : null,
            $keys,
        );
    }
}
