<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Integration\Fixtures;

use AsyncAws\DynamoDb\DynamoDbClient;
use AsyncAws\Core\Exception\Http\ClientException;
use Shopware\DynamodbDalBundle\Client\Client;
use Shopware\DynamodbDalBundle\Client\Input\BatchWriteInput;
use Shopware\DynamodbDalBundle\Client\Input\PutInput;
use Shopware\DynamodbDalBundle\Client\Input\QueryInput;
use Shopware\DynamodbDalBundle\Client\Input\TransactWriteInput;
use Shopware\DynamodbDalBundle\Client\Input\UpdateInput;
use Shopware\DynamodbDalBundle\Client\Key;
use Shopware\DynamodbDalBundle\Expression\Filter;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\Entity\RecordEntity;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The application code the profiler suite requests pages from. An action that needs the line it called the DAL
 * from answers with it.
 */
final readonly class ProfiledController
{
    public const string TENANT = 'tenant-1';

    public function __construct(
        private Client $client,
        private DynamoDbClient $dynamo,
    ) {
    }

    public function put(): Response
    {
        $this->client->put(new PutInput(RecordEntity::create(self::TENANT, 'a'))); $line = __LINE__;

        return new Response((string) $line);
    }

    public function read(): Response
    {
        $this->seed();

        $keys = [new Key(RecordEntity::class, self::TENANT, 'a'), new Key(RecordEntity::class, self::TENANT, 'b')];

        iterator_to_array($this->client->search($this->query())); $line = __LINE__;
        array_map($this->client->find(...), $keys);
        $this->client->findMany($keys)->toArray();
        $this->client->count($this->query());

        return new Response((string) $line);
    }

    public function transact(): Response
    {
        $entity = RecordEntity::create(self::TENANT, 'a');
        $entity->meta = ['first' => 'one'];
        $this->client->put(new PutInput($entity));

        // A nested path leaves the entity to be read back, which the writer does through the reader
        $this->client->transactWrite(new TransactWriteInput(new UpdateInput($entity, ['meta.first' => 'renewed'])));

        return new Response();
    }

    public function rejected(): Response
    {
        $entity = RecordEntity::create(self::TENANT, 'a');

        try {
            // DynamoDB refuses a batch that writes one key twice
            $this->client->batchWrite(new BatchWriteInput([$entity, clone $entity]));
        } catch (ClientException) {
        }

        return new Response();
    }

    public function fail(): Response
    {
        $this->client->put(new PutInput(RecordEntity::create(self::TENANT, 'a')));

        throw new \RuntimeException('Rendered as an error page, in a sub-request');
    }

    public function streamed(): Response
    {
        $this->seed();

        $records = $this->client->search($this->query()); $line = __LINE__;

        return new StreamedResponse(static function () use ($records, $line): void {
            foreach ($records as $record) {
                echo $record->id;
            }

            echo ':' . $line;
        });
    }

    public function outside(): Response
    {
        $this->dynamo->getItem(['TableName' => DynamoDbTestKernel::TABLES['record'], 'Key' => ['tenantId' => ['S' => self::TENANT], 'id' => ['S' => 'a']]])->resolve();

        return new Response();
    }

    private function seed(): void
    {
        $this->client->batchWrite(new BatchWriteInput([RecordEntity::create(self::TENANT, 'a'), RecordEntity::create(self::TENANT, 'b')]));
    }

    /**
     * @return QueryInput<RecordEntity>
     */
    private function query(): QueryInput
    {
        return new QueryInput(RecordEntity::class, Filter::keyFilter(Filter::equals('tenantId', self::TENANT)));
    }
}
