<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Profiler;

/**
 * What the profiler reads off a call to DynamoDB: the application frame that entered the DAL, and the operation a
 * request names.
 *
 * @internal
 *
 * @codeCoverageIgnore
 */
final class DynamoDbCall
{
    private const string DAL_NAMESPACE = 'Shopware\\DynamodbDalBundle\\';

    private const string TARGET_HEADER = 'x-amz-target';

    private function __construct()
    {
    }

    /**
     * The application frame that entered the DAL: the frame right outside the outermost DAL frame. Keying on the
     * namespace rather than on a base class keeps this working for any shape of caller — a repository, a service,
     * a controller.
     *
     * @return array{class: class-string, method: string, file: ?string, line: ?int}|null
     */
    public static function caller(): ?array
    {
        $trace = debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS, 30);

        $lastDalFrame = null;
        foreach ($trace as $index => $frame) {
            if (str_starts_with($frame['class'] ?? '', self::DAL_NAMESPACE)) {
                $lastDalFrame = $index;
            }
        }

        if ($lastDalFrame === null) {
            return null;
        }

        $frame = $trace[$lastDalFrame + 1] ?? null;
        $class = $frame['class'] ?? null;
        if ($frame === null || $class === null) {
            return null;
        }

        return [
            'class' => $class,
            'method' => $frame['function'],
            'file' => $frame['file'] ?? null,
            'line' => $frame['line'] ?? null,
        ];
    }

    /**
     * The request's `x-amz-target` header, e.g. `DynamoDB_20120810.Query`, or `null` without one.
     *
     * @param iterable<mixed, mixed> $headers
     */
    public static function target(iterable $headers): ?string
    {
        foreach ($headers as $key => $value) {
            // Symfony takes headers both as a `name => value` map and as raw `'name: value'` lines.
            $line = \is_string($key) ? $key . ': ' . self::headerValue($value) : self::headerValue($value);
            if (stripos($line, self::TARGET_HEADER . ':') !== 0) {
                continue;
            }

            return trim(substr($line, \strlen(self::TARGET_HEADER) + 1));
        }

        return null;
    }

    /**
     * A header's value as one string; a name may carry a list of values, of which the first is ours.
     */
    private static function headerValue(mixed $value): string
    {
        if (\is_array($value)) {
            $value = $value[0] ?? null;
        }

        return \is_scalar($value) ? (string) $value : '';
    }
}
