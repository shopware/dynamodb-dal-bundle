<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Command;

use Shopware\DynamodbDalBundle\Command\DALBaselineDumpCommand;
use Shopware\DynamodbDalBundle\Tests\Unit\Fixtures\CommandDefinitions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\BufferedOutput;

#[CoversClass(DALBaselineDumpCommand::class)]
class DALBaselineDumpCommandTest extends TestCase
{
    public function testDumpsKeysIndexesAndStoredFieldTypesSortedByName(): void
    {
        $output = new BufferedOutput();
        $command = new DALBaselineDumpCommand([
            'order' => CommandDefinitions::order(),
            'config' => CommandDefinitions::customer(),
        ]);

        static::assertSame(Command::SUCCESS, $command->__invoke($output));

        $expected = <<<'JSON'
            {
                "config": {
                    "hashKey": "tenantId",
                    "rangeKey": null,
                    "indexes": {},
                    "fields": {
                        "tenantId": {
                            "type": "S",
                            "required": true
                        }
                    }
                },
                "order": {
                    "hashKey": "tenantId",
                    "rangeKey": "externalId",
                    "indexes": {
                        "referenceIndex": {
                            "hashKey": "reference",
                            "rangeKey": "revision"
                        },
                        "revisionIndex": {
                            "hashKey": "tenantId",
                            "rangeKey": "revision"
                        }
                    },
                    "fields": {
                        "externalId": {
                            "type": "S",
                            "required": true
                        },
                        "groups": {
                            "type": "M",
                            "required": false,
                            "values": {
                                "type": "L",
                                "values": {
                                    "type": "S"
                                }
                            }
                        },
                        "note": {
                            "type": "S",
                            "required": false
                        },
                        "reference": {
                            "type": "S",
                            "required": true
                        },
                        "revision": {
                            "type": "N",
                            "required": false
                        },
                        "tags": {
                            "type": "L",
                            "required": false,
                            "values": {
                                "type": "S"
                            }
                        },
                        "tenantId": {
                            "type": "S",
                            "required": true
                        }
                    }
                }
            }

            JSON;

        static::assertSame($expected, $output->fetch());
    }
}
