<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Integration\DynamoDb;

use AsyncAws\Core\Exception\Exception as AsyncAwsException;
use AsyncAws\DynamoDb\DynamoDbClient;
use AsyncAws\DynamoDb\Enum\KeyType;
use AsyncAws\DynamoDb\Enum\ScalarAttributeType;
use PHPUnit\Framework\TestCase;
use Shopware\DynamodbDalBundle\Client\Client;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\EntityDefinitionRegistry;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\DynamoDbTestKernel;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Shopware\DynamodbDalBundle\AbstractEntity;

/**
 * Base for the suites that drive the DAL against a real DynamoDB.
 *
 * The endpoint comes from `DYNAMODB_ENDPOINT` and defaults to the `compose.yaml` service. When nothing
 * answers there the whole suite skips, so `composer phpunit` stays green on a machine without a
 * container runtime — run `docker compose up -d` to get the coverage.
 *
 * The fixture tables are created once per class and emptied before every test, so a scan or a count
 * only ever sees what the test itself wrote.
 */
abstract class DynamoDbTestCase extends TestCase
{
    protected const string TENANT = 'tenant-1';

    private static ?DynamoDbTestKernel $kernel = null;

    private static ?string $skipReason = null;

    public static function setUpBeforeClass(): void
    {
        $endpoint = self::endpoint();

        if (!self::isListening($endpoint)) {
            self::$skipReason = self::skipReason($endpoint, 'nothing is listening there');

            return;
        }

        self::$kernel = new DynamoDbTestKernel($endpoint);
        self::$kernel->boot();

        // Something answering on the port is not necessarily DynamoDB, so ask it for a table list
        // before a whole suite fails against, say, a web server that happens to hold that port.
        try {
            self::bootedDynamoClient()->listTables()->resolve();
        } catch (\Throwable $exception) {
            self::$kernel->shutdown();
            self::$kernel = null;
            self::$skipReason = self::skipReason($endpoint, $exception->getMessage());

            return;
        }

        self::createTables();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$kernel !== null) {
            self::dropTables();
            self::$kernel->shutdown();
        }

        self::$kernel = null;
        self::$skipReason = null;
    }

    protected function setUp(): void
    {
        if (self::$skipReason !== null) {
            static::markTestSkipped(self::$skipReason);
        }

        $this->truncateTables();
    }

    protected function container(): ContainerInterface
    {
        $kernel = self::$kernel;
        static::assertNotNull($kernel);

        return $kernel->getContainer();
    }

    protected function client(): Client
    {
        $client = $this->container()->get('test.' . Client::class);
        static::assertInstanceOf(Client::class, $client);

        return $client;
    }

    protected function dynamo(): DynamoDbClient
    {
        $client = $this->container()->get('test.' . DynamoDbClient::class);
        static::assertInstanceOf(DynamoDbClient::class, $client);

        return $client;
    }

    protected function registry(): EntityDefinitionRegistry
    {
        $registry = $this->container()->get('test.' . EntityDefinitionRegistry::class);
        static::assertInstanceOf(EntityDefinitionRegistry::class, $registry);

        return $registry;
    }

    /**
     * @return EntityDefinition<AbstractEntity>
     */
    protected function definition(string $name): EntityDefinition
    {
        return $this->registry()->get($name);
    }

    private static function endpoint(): string
    {
        $endpoint = $_SERVER['DYNAMODB_ENDPOINT'] ?? $_ENV['DYNAMODB_ENDPOINT'] ?? getenv('DYNAMODB_ENDPOINT');

        return \is_string($endpoint) && $endpoint !== '' ? $endpoint : 'http://127.0.0.1:8000';
    }

    private static function skipReason(string $endpoint, string $detail): string
    {
        return \sprintf(
            'No DynamoDB at %s (%s). Start one with `docker compose up -d`, or point DYNAMODB_ENDPOINT at your own.',
            $endpoint,
            $detail,
        );
    }

    private static function isListening(string $endpoint): bool
    {
        $url = parse_url($endpoint);
        $host = \is_array($url) ? ($url['host'] ?? null) : null;
        $port = \is_array($url) ? ($url['port'] ?? null) : null;

        if (!\is_string($host) || !\is_int($port)) {
            return false;
        }

        $socket = @fsockopen($host, $port, $errno, $error, 0.5);
        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }

    private static function createTables(): void
    {
        $dynamo = self::bootedDynamoClient();

        $dynamo->createTable([
            'TableName' => DynamoDbTestKernel::TABLES['record'],
            'BillingMode' => 'PAY_PER_REQUEST',
            'AttributeDefinitions' => [
                ['AttributeName' => 'tenantId', 'AttributeType' => ScalarAttributeType::S],
                ['AttributeName' => 'id', 'AttributeType' => ScalarAttributeType::S],
                ['AttributeName' => 'status', 'AttributeType' => ScalarAttributeType::S],
                ['AttributeName' => 'createdAt', 'AttributeType' => ScalarAttributeType::N],
            ],
            'KeySchema' => [
                ['AttributeName' => 'tenantId', 'KeyType' => KeyType::HASH],
                ['AttributeName' => 'id', 'KeyType' => KeyType::RANGE],
            ],
            'GlobalSecondaryIndexes' => [[
                'IndexName' => 'statusIndex',
                'KeySchema' => [
                    ['AttributeName' => 'status', 'KeyType' => KeyType::HASH],
                    ['AttributeName' => 'createdAt', 'KeyType' => KeyType::RANGE],
                ],
                'Projection' => ['ProjectionType' => 'ALL'],
            ]],
        ])->resolve();

        $dynamo->createTable([
            'TableName' => DynamoDbTestKernel::TABLES['archive'],
            'BillingMode' => 'PAY_PER_REQUEST',
            'AttributeDefinitions' => [['AttributeName' => 'id', 'AttributeType' => ScalarAttributeType::S]],
            'KeySchema' => [['AttributeName' => 'id', 'KeyType' => KeyType::HASH]],
        ])->resolve();

        $dynamo->createTable([
            'TableName' => DynamoDbTestKernel::TABLES['normalized'],
            'BillingMode' => 'PAY_PER_REQUEST',
            'AttributeDefinitions' => [
                ['AttributeName' => 'pk', 'AttributeType' => ScalarAttributeType::S],
                ['AttributeName' => 'id', 'AttributeType' => ScalarAttributeType::S],
            ],
            'KeySchema' => [
                ['AttributeName' => 'pk', 'KeyType' => KeyType::HASH],
                ['AttributeName' => 'id', 'KeyType' => KeyType::RANGE],
            ],
        ])->resolve();
    }

    private static function dropTables(): void
    {
        $dynamo = self::bootedDynamoClient();

        foreach (DynamoDbTestKernel::TABLES as $table) {
            try {
                $dynamo->deleteTable(['TableName' => $table])->resolve();
            } catch (AsyncAwsException) {
                // Already gone, or never created because the class failed on the way up.
            }
        }
    }

    private static function bootedDynamoClient(): DynamoDbClient
    {
        $kernel = self::$kernel;
        \assert($kernel !== null);

        $client = $kernel->getContainer()->get('test.' . DynamoDbClient::class);
        \assert($client instanceof DynamoDbClient);

        return $client;
    }

    /**
     * Empties every fixture table by scanning its keys and deleting them. The tables hold a handful of
     * rows per test, so this is cheaper than recreating them.
     */
    private function truncateTables(): void
    {
        $dynamo = $this->dynamo();

        foreach (DynamoDbTestKernel::TABLES as $name => $table) {
            $keyFields = $this->definition($name)->getKeySchema()->getFields();

            $scan = $dynamo->scan([
                'TableName' => $table,
                'ProjectionExpression' => implode(', ', array_map(static fn (string $field): string => '#' . $field, $keyFields)),
                'ExpressionAttributeNames' => array_combine(
                    array_map(static fn (string $field): string => '#' . $field, $keyFields),
                    $keyFields,
                ),
            ]);

            foreach ($scan->getItems() as $item) {
                $dynamo->deleteItem(['TableName' => $table, 'Key' => $item])->resolve();
            }
        }
    }
}
