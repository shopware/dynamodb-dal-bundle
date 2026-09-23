<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Fixtures\Entity;

enum TestStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
}
