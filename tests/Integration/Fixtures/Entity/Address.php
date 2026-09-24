<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\Entity;

/**
 * A value object the bundle stores as JSON, through {@see \JsonSerializable}, but reads back only as the
 * decoded array. {@see ContactEntityNormalizer} turns that into an Address again.
 */
final readonly class Address implements \JsonSerializable
{
    public function __construct(
        public string $street,
        public string $city,
    ) {
    }

    /**
     * @param array{street: string, city: string} $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['street'], $data['city']);
    }

    /**
     * @return array{street: string, city: string}
     */
    public function jsonSerialize(): array
    {
        return ['street' => $this->street, 'city' => $this->city];
    }
}
