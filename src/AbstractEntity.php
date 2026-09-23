<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle;

abstract class AbstractEntity
{
    final public function __construct()
    {
    }

    /**
     * Primarily used to get all object values for serialization.
     *
     * @return array<string, mixed>
     */
    public function getVars(): array
    {
        return get_object_vars($this);
    }

    /**
     * Primarily used to set all object values from deserialization.
     *
     * @param iterable<string, mixed> $vars
     *
     * @throws \TypeError
     */
    public function setVars(iterable $vars): static
    {
        foreach ($vars as $name => $value) {
            if (property_exists($this, $name)) {
                /** @phpstan-ignore-next-line property.dynamicName -- guarded by property_exists */
                $this->{$name} = $value;
            }
        }

        return $this;
    }
}
