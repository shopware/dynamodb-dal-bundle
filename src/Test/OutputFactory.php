<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Test;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Client\Client;
use Shopware\DynamodbDalBundle\Client\Input\QueryInput;
use Shopware\DynamodbDalBundle\Client\Input\ScanInput;
use Shopware\DynamodbDalBundle\Client\Output\GetOutput;
use Shopware\DynamodbDalBundle\Client\Output\SearchOutput;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;

/**
 * Builds the outputs a test double of {@see Client} returns.
 * The outputs behave as the real ones do: they stream once, and a search
 * output is limited, paged and read backward as its input says.
 */
final class OutputFactory
{
    private function __construct()
    {
    }

    /**
     * A stand-in for {@see Client::search()} that finds `$entities`.
     *
     * `$keyOf` gives an entity's key attributes as DynamoDB returns them, the table key plus an index's for a query of
     * one, as strings and numbers: `['id' => 'o-1']`. A page's tokens are built from them as a real search builds
     * them, so a test can compare a token with one a page it built itself hands out. Without `$keyOf`, a page still
     * has tokens, but every search refuses them.
     *
     * @template Entity of AbstractEntity
     *
     * @param ScanInput<Entity>|QueryInput<Entity> $search
     * @param iterable<Entity> $entities
     * @param ?\Closure(Entity): array<string, string|int|float> $keyOf
     *
     * @return SearchOutput<Entity>
     */
    public static function search(ScanInput|QueryInput $search, iterable $entities, ?\Closure $keyOf = null): SearchOutput
    {
        $source = static function () use ($entities, $keyOf): \Generator {
            $position = 0;
            foreach ($entities as $entity) {
                if ($keyOf === null) {
                    yield $position++ => $entity;

                    continue;
                }

                yield array_map(
                    static fn (string|int|float $value): AttributeValue => \is_string($value)
                        ? new AttributeValue(['S' => $value])
                        : new AttributeValue(['N' => (string) $value]),
                    $keyOf($entity),
                ) => $entity;
            }
        };

        return new SearchOutput($source(), $search);
    }

    /**
     * A stand-in for {@see Client::get()} or {@see Client::findMany()} that finds `$entities`.
     *
     * @template Entity of AbstractEntity
     *
     * @param iterable<Entity> $entities
     *
     * @return GetOutput<Entity>
     */
    public static function get(iterable $entities): GetOutput
    {
        $source = static function () use ($entities): \Generator {
            foreach ($entities as $entity) {
                yield $entity;
            }
        };

        return new GetOutput($source());
    }
}
