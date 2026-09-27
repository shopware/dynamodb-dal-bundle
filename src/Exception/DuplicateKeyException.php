<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Exception;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Client\Key;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;

/**
 * A batch write or a transaction names the same key twice, such as two puts, or a put and a delete. DynamoDB refuses a
 * request that does, after the requests before it were written, and applies both where they are sent apart, so the
 * bundle refuses the batch or transaction before it sends any of it.
 */
final class DuplicateKeyException extends \RuntimeException implements DALException
{
    /**
     * @param AbstractEntity|Key<AbstractEntity> $key - the entity or key that names the key a second time
     */
    public function __construct(
        public readonly EntityDefinition $entityDefinition,
        public readonly AbstractEntity|Key $key,
    ) {
        parent::__construct(\sprintf(
            'A batch write or transaction names the same key of item "%s" twice',
            $entityDefinition->getName(),
        ));
    }
}
