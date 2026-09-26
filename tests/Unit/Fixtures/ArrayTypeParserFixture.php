<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Fixtures;

/**
 * Fixture for ArrayTypeParser::getDocblockVarType() tests, which read the `@var` docblocks of these properties.
 */
class ArrayTypeParserFixture
{
    public array $noDocblock; // @phpstan-ignore-line missingType.iterableValue

    /**
     * @var list<string>
     */
    public array $listType;

    /**
     * @var array<string, int>
     */
    public array $mapType;
}
