<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Client\Read;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use AsyncAws\DynamoDb\Input\QueryInput as DynamoDbQueryInput;
use AsyncAws\DynamoDb\Input\ScanInput as DynamoDbScanInput;

/**
 * A scan or query as DynamoDB takes it, and what its items are read as.
 *
 * @internal
 *
 * @template-covariant Entity of AbstractEntity
 */
final readonly class PreparedSearch
{
    /**
     * @param EntityDefinition<Entity> $definition
     * @param DynamoDbQueryInput|DynamoDbScanInput $input - of the first page; a later one resumes from a clone of it
     * @param array<string, true> $startKeyFields - the attributes DynamoDB takes as `ExclusiveStartKey` to resume after an item: the table key, and the index key of an index query
     */
    public function __construct(
        public EntityDefinition $definition,
        public DynamoDbQueryInput|DynamoDbScanInput $input,
        public array $startKeyFields,
    ) {
    }
}
