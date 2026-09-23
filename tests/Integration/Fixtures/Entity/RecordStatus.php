<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\Entity;

enum RecordStatus: string
{
    case Open = 'open';
    case Done = 'done';
    case Failed = 'failed';
}
