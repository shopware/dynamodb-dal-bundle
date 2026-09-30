<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Client\Input;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Client\Output\Page;
use Shopware\DynamodbDalBundle\Expression\Contract\FilterInterface;

/**
 * A read request that scans a whole table, optionally filtered.
 *
 * @template-covariant Entity of AbstractEntity
 */
final readonly class ScanInput
{
    /**
     * @param class-string<Entity> $class - the entity class whose table is scanned
     * @param ?FilterInterface $filter - scan's `FilterExpression`: drops items after they are read, so they still cost read capacity
     * @param bool $consistentRead - scan's `ConsistentRead`. Strongly consistent read; off by default (eventually consistent reads are cheaper)
     * @param ?string $cursor - a {@see Page::$next} token of this same scan; `null` starts from the beginning
     * @param ?int $limit - the most items to return; one below 1 counts as 1. With a filter, whole pages are read and cut to size here, as DynamoDB applies its `Limit` before filtering
     */
    public function __construct(
        public string $class,
        public ?FilterInterface $filter = null,
        public bool $consistentRead = false,
        public ?string $cursor = null,
        public ?int $limit = null,
    ) {
    }

    /**
     * @return self<Entity>
     */
    public function withFilter(?FilterInterface $filter): self
    {
        return new self($this->class, $filter, $this->consistentRead, $this->cursor, $this->limit);
    }

    /**
     * @return self<Entity>
     */
    public function withConsistentRead(bool $consistentRead = true): self
    {
        return new self($this->class, $this->filter, $consistentRead, $this->cursor, $this->limit);
    }

    /**
     * @return self<Entity>
     */
    public function withCursor(?string $cursor): self
    {
        return new self($this->class, $this->filter, $this->consistentRead, $cursor, $this->limit);
    }

    /**
     * @return self<Entity>
     */
    public function withLimit(?int $limit): self
    {
        return new self($this->class, $this->filter, $this->consistentRead, $this->cursor, $limit);
    }
}
