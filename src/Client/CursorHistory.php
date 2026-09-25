<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Client;

use Shopware\DynamodbDalBundle\Client\Output\Page;
use Shopware\DynamodbDalBundle\Exception\InvalidCursorException;

/**
 * The positions a paginated view visited, carried along (typically in one URL parameter) for what DynamoDB
 * cannot answer by itself: going back through a scan, which has no order to reverse, and the page number.
 * Position `i` is the one that produced page `i + 2`; the empty history is page 1.
 *
 * A position is a {@see Page} token, or several named ones {@see combine()}d for a page merged from several
 * searches. A view over a single query needs no history to go back — {@see Page::$previous} reads it backward.
 */
final readonly class CursorHistory
{
    /**
     * The non-empty {@see toString()} form, for validating a history where it enters, e.g. as a URL parameter.
     */
    public const string PATTERN = '/^[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*$/';

    private const string SEPARATOR = '.';

    /**
     * @var list<string>
     */
    public array $positions;

    /**
     * @param list<string> $positions - position `i` produced page `i + 2`
     *
     * @throws InvalidCursorException if a position is not URL-safe; a token or combined position always is
     */
    public function __construct(array $positions = [])
    {
        foreach ($positions as $position) {
            if (preg_match('/^[A-Za-z0-9_-]+$/', $position) !== 1) {
                throw new InvalidCursorException('a history position is not a token');
            }
        }

        $this->positions = array_values($positions);
    }

    /**
     * Restores a history from its {@see toString()} form; `null` or `''` is page 1.
     *
     * @throws InvalidCursorException if the string is not a history
     */
    public static function fromString(?string $history): self
    {
        return new self($history === null || $history === '' ? [] : explode(self::SEPARATOR, $history));
    }

    /**
     * Folds the positions of a page merged from several searches — one token per search, e.g. one per status
     * — into a single position. Leave out a search still on its first page; it has no token.
     *
     * @param array<string, string> $positions - name => token
     *
     * @throws InvalidCursorException if a name or a token is not valid UTF-8
     */
    public static function combine(array $positions): string
    {
        try {
            $json = json_encode($positions, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidCursorException('a name or a token is not valid UTF-8', $e);
        }

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * The named tokens of a {@see combine()}d position; `null` (page 1) has none.
     *
     * @throws InvalidCursorException if the position was not combined
     *
     * @return array<string, string> - name => token
     */
    public static function split(?string $position): array
    {
        if ($position === null) {
            return [];
        }

        $json = base64_decode(strtr($position, '-_', '+/'), true);
        $positions = $json !== false && json_validate($json) ? json_decode($json, true) : null;

        if (!\is_array($positions)) {
            throw new InvalidCursorException('the history position does not name several tokens');
        }

        $tokens = [];
        foreach ($positions as $name => $token) {
            if (!\is_string($name) || !\is_string($token)) {
                throw new InvalidCursorException('the history position does not name several tokens');
            }

            $tokens[$name] = $token;
        }

        return $tokens;
    }

    /**
     * The URL-safe form: the positions joined by `.`, which no token contains; `''` on page 1.
     */
    public function toString(): string
    {
        return implode(self::SEPARATOR, $this->positions);
    }

    /**
     * The position the current page resumes from, or `null` on page 1.
     */
    public function current(): ?string
    {
        return $this->positions === [] ? null : $this->positions[array_key_last($this->positions)];
    }

    /**
     * The 1-based current page number.
     */
    public function page(): int
    {
        return \count($this->positions) + 1;
    }

    /**
     * The history one page back, or `null` when already on page 1.
     */
    public function previous(): ?self
    {
        /** @phpstan-ignore-next-line missingType.checkedException -- the positions were validated on construction */
        return $this->positions === [] ? null : new self(\array_slice($this->positions, 0, -1));
    }

    /**
     * The history one page further, resuming from `$position`. `null` for a `null` position, so {@see Page::$next}
     * can be passed as it is: it is `null` on the last page.
     *
     * @throws InvalidCursorException if the position is not URL-safe; a token or combined position always is
     *
     * @return ($position is null ? null : self)
     */
    public function advance(?string $position): ?self
    {
        return $position !== null ? new self([...$this->positions, $position]) : null;
    }
}
