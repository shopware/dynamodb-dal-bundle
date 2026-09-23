<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Client\Cursor;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * The ordered page history of a cursor-paginated view: element `i` is the resume position that produced
 * page `i + 1` (empty = page 1). DynamoDB cursors are forward-only, so a multi-step "Previous" carries the
 * visited pages' positions along (in the URL) rather than recomputing them.
 *
 * @implements \IteratorAggregate<int, CursorCollection>
 */
#[Exclude]
final class CursorHistory implements \IteratorAggregate
{
    /**
     * @var list<CursorCollection>
     */
    public readonly array $pages;

    /**
     * @param list<CursorCollection> $pages - index `i` => resume position of page `i + 1`; empty = page 1
     */
    public function __construct(array $pages = [])
    {
        $this->pages = array_values(array_filter($pages, static fn (CursorCollection $page): bool => !$page->isEmpty()));
    }

    /**
     * The current page's resume position (the last entry), or `null` on page 1.
     */
    public function current(): ?CursorCollection
    {
        if ($this->pages === []) {
            return null;
        }

        return $this->pages[array_key_last($this->pages)];
    }

    /**
     * The 1-based current page number.
     */
    public function page(): int
    {
        return \count($this->pages) + 1;
    }

    /**
     * The history one page back (this history without its last page), or `null` when already on page 1.
     */
    public function previous(): ?self
    {
        if ($this->pages === []) {
            return null;
        }

        return new self(\array_slice($this->pages, 0, -1));
    }

    /**
     * The history extended by the next page's resume position — what the "Next" link carries.
     */
    public function append(CursorCollection $page): self
    {
        return new self([...$this->pages, $page]);
    }

    public function isEmpty(): bool
    {
        return $this->pages === [];
    }

    /**
     * @return \Traversable<int, CursorCollection>
     */
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->pages);
    }
}
