<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Client;

use Shopware\DynamodbDalBundle\Client\Output\Page;
use Shopware\DynamodbDalBundle\Exception\InvalidCursorException;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;

/**
 * The decoded form of a {@see Page} token: a boundary item's raw key attributes, exactly as DynamoDB takes
 * them for `ExclusiveStartKey`, plus the direction to read in. Callers only ever see the opaque token string
 * — its content is only meaningful to the query that produced it.
 *
 * @internal
 */
final readonly class Cursor
{
    /**
     * @param array<string, AttributeValue> $key - the table key attributes, plus the index key attributes for a GSI query
     * @param bool $backward - reads towards the start of the query, for a "previous page"
     */
    public function __construct(
        public array $key,
        public bool $backward = false,
    ) {
    }

    /**
     * The URL-safe token form: base64url of `{"k": {attribute: {S|N|B: value}}, "b": true}`.
     *
     * @throws InvalidCursorException if a key attribute is not valid UTF-8, which none DynamoDB returns is
     */
    public function encode(): string
    {
        $key = array_map(static fn (AttributeValue $value): array => $value->requestBody(), $this->key);
        $payload = $this->backward ? ['k' => $key, 'b' => true] : ['k' => $key];

        try {
            $json = json_encode($payload, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidCursorException('a key attribute is not valid UTF-8', $e);
        }

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * @throws InvalidCursorException
     */
    public static function decode(string $token): self
    {
        $json = base64_decode(strtr($token, '-_', '+/'), true);
        $payload = $json !== false && json_validate($json) ? json_decode($json, true) : null;

        if (!\is_array($payload) || !\is_array($payload['k'] ?? null) || $payload['k'] === []) {
            throw new InvalidCursorException('not a pagination token');
        }

        $backward = $payload['b'] ?? false;
        if (!\is_bool($backward)) {
            throw new InvalidCursorException('malformed direction');
        }

        $key = [];
        foreach ($payload['k'] as $name => $scalar) {
            if (!\is_string($name) || !\is_array($scalar)) {
                throw new InvalidCursorException('malformed key');
            }

            $key[$name] = self::toAttributeValue($name, $scalar);
        }

        return new self($key, $backward);
    }

    /**
     * A DynamoDB key attribute is always a single `S`, `N` or `B`; anything else is refused rather than
     * passed on to DynamoDB.
     *
     * @param array<array-key, mixed> $scalar
     *
     * @throws InvalidCursorException
     */
    private static function toAttributeValue(string $name, array $scalar): AttributeValue
    {
        $type = array_key_first($scalar);
        $value = $scalar[$type] ?? null;

        if (\count($scalar) !== 1 || !\in_array($type, ['S', 'N', 'B'], true) || !\is_string($value)) {
            throw new InvalidCursorException(\sprintf('key attribute "%s" is not a string, number or binary', $name));
        }

        if ($type === 'B') {
            // requestBody() base64-encodes binaries, the constructor takes them raw.
            $value = base64_decode($value, true);
            if ($value === false) {
                throw new InvalidCursorException(\sprintf('key attribute "%s" is not valid base64', $name));
            }
        }

        return new AttributeValue([$type => $value]);
    }
}
