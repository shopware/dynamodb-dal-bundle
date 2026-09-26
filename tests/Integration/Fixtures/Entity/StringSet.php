<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\Entity;

/**
 * Distinct strings, stored as a DynamoDB string set (`SS`) by {@see StringSetFieldSerializer}. The bundle has
 * no set type of its own, so this is how `Update::addToSet()` and `Update::removeFromSet()` reach a set at all.
 */
final readonly class StringSet
{
    /**
     * @var list<string>
     */
    public array $values;

    public function __construct(string ...$values)
    {
        $this->values = array_values(array_unique($values));
    }
}
