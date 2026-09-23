<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Client\Output;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Client\Cursor\Cursor;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * @template Entity of AbstractEntity
 */
#[Exclude]
readonly class Page
{
    /**
     * @param list<Entity> $items
     * @param ?Cursor $nextCursor - Resumes a query or scan after the last item returned. `null` means there are no further pages.
     */
    public function __construct(
        public array $items,
        public ?Cursor $nextCursor,
    ) {
    }
}
