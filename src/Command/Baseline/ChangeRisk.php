<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Command\Baseline;

/**
 * What a change of the baseline risks once it is deployed, from the worst.
 *
 * @internal
 */
enum ChangeRisk: string
{
    /**
     * Stored rows or requests fail, unless something else handles it, such as a normalizer or a migration.
     */
    case Breaking = 'breaking';

    /**
     * Works only once a table is changed too, or drops stored data.
     */
    case Caution = 'caution';

    case Safe = 'safe';

    public function describe(): string
    {
        return match ($this) {
            self::Breaking => 'Stored rows or requests fail once this is deployed, unless it is handled',
            self::Caution => 'Needs a change to a table before it is deployed, or drops stored data',
            self::Safe => 'Nothing to do',
        };
    }
}
