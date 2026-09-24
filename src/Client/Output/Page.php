<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Client\Output;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Client\Cursor;
use Shopware\DynamodbDalBundle\Client\Input\QueryInput;
use Shopware\DynamodbDalBundle\Client\Input\ScanInput;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;

/**
 * @template Entity of AbstractEntity
 */
readonly class Page
{
    /**
     * @internal - construction is internal, public properties not
     * 
     * @param list<Entity> $items
     * @param ?string $next - resumes after the last item; `null` if there is no further page
     * @param ?string $previous - resumes before the first item; `null` on the first page and always for a scan, which has no order to reverse
     * @param list<array<string, AttributeValue>> $keys - @internal the raw start key of each item, in the order of `$items`
     */
    public function __construct(
        public array $items,
        public ?string $next = null,
        public ?string $previous = null,
        private array $keys = [],
    ) {
    }

    /**
     * A token resuming the same search right after `$item` — for a page merged from several searches, where
     * the position to continue from is the last item that made it onto the merged page, not {@see $next}.
     *
     * @param Entity $item - one of {@see $items}, by identity
     *
     * @throws \InvalidArgumentException if `$item` is not on this page
     * @throws \JsonException if a key attribute of `$item` is not valid UTF-8
     */
    public function cursorAfter(AbstractEntity $item): string
    {
        return new Cursor($this->keyOf($item))->encode();
    }

    /**
     * A token reading the same search backward from right before `$item`; the counterpart of {@see cursorAfter()}.
     * A scan has no order to reverse, so it refuses the token when it is used.
     *
     * @param Entity $item - one of {@see $items}, by identity
     *
     * @throws \InvalidArgumentException if `$item` is not on this page
     * @throws \JsonException if a key attribute of `$item` is not valid UTF-8
     */
    public function cursorBefore(AbstractEntity $item): string
    {
        return new Cursor($this->keyOf($item), backward: true)->encode();
    }

    /**
     * @throws \InvalidArgumentException if `$item` is not on this page
     *
     * @return array<string, AttributeValue>
     */
    private function keyOf(AbstractEntity $item): array
    {
        $index = array_search($item, $this->items, true);
        if (!\is_int($index)) {
            throw new \InvalidArgumentException(\sprintf('The %s is not an item of this page.', $item::class));
        }

        return $this->keys[$index] ?? [];
    }
}
