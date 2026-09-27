<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Profiler;

/**
 * One call the application made into the DAL: the method it called and from where, the time spent in it, and the
 * DynamoDB requests it sent. A key read or search streams, so its time and requests grow while the application
 * reads it.
 *
 * @internal
 */
final class DalCall
{
    private const string DAL_NAMESPACE = 'Shopware\\DynamodbDalBundle\\';

    public private(set) int $durationNs = 0;

    /**
     * @var ?array{class: class-string<\Throwable>, message: string}
     */
    public private(set) ?array $failure = null;

    /**
     * @var list<DynamoDbRequest>
     */
    public private(set) array $requests = [];

    /**
     * Whether each class of the DAL's namespace seen so far is defined in the bundle's source, by class name.
     *
     * @var array<class-string, bool>
     */
    private static array $isSource = [];

    /**
     * @param string $method - the DAL method the application called, e.g. `search`
     * @param list<class-string> $entities
     * @param ?string $callerClass - `null` for a plain function or a script
     * @param ?string $callerMethod - `null` for a script
     */
    private function __construct(
        public readonly string $method,
        public readonly array $entities,
        public readonly ?string $callerClass,
        public readonly ?string $callerMethod,
        public readonly ?string $callerFile,
        public readonly ?int $callerLine,
    ) {
    }

    /**
     * Opens a call for the DAL method the application is calling right now, as the call stack shows it: the outermost
     * frame inside the DAL is that method, and the frame around it the caller. Keying on the namespace rather than
     * on a base class keeps this working for any shape of caller — a repository, a service, a controller, a template.
     *
     * @param list<class-string> $entities
     */
    public static function enter(array $entities): self
    {
        $trace = debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS);

        // This method is in the DAL itself, so there always is one
        $outermost = 0;
        foreach ($trace as $index => $frame) {
            if (self::isDal($frame['class'] ?? '')) {
                $outermost = $index;
            }
        }

        // A frame's file and line are where its function was called. A built-in that calls back into the DAL, like
        // array_map(), leaves the DAL method without either, so the call site is the built-in's
        $site = $outermost;
        while (isset($trace[$site]) && !isset($trace[$site]['file'])) {
            ++$site;
        }

        $caller = $trace[$site + 1] ?? null;

        return new self(
            $trace[$outermost]['function'],
            $entities,
            $caller['class'] ?? null,
            $caller['function'] ?? null,
            $trace[$site]['file'] ?? null,
            $trace[$site]['line'] ?? null,
        );
    }

    /**
     * Adds one step of the call's work, see {@see DalCallTracer::run()}. The first failure is the one the
     * application got.
     *
     * @param list<DynamoDbRequest> $requests
     */
    public function record(int $durationNs, array $requests, ?\Throwable $failure): void
    {
        $this->durationNs += $durationNs;
        $this->requests = [...$this->requests, ...$requests];

        if ($failure !== null) {
            $this->failure ??= ['class' => $failure::class, 'message' => $failure->getMessage()];
        }
    }

    /**
     * Whether the class is defined in the bundle's source. The namespace alone does not tell, as code outside the
     * source may share it, such as the bundle's own tests, which call the DAL the way an application does.
     */
    private static function isDal(string $class): bool
    {
        if (!str_starts_with($class, self::DAL_NAMESPACE) || !class_exists($class, false)) {
            return false;
        }

        return self::$isSource[$class] ??= str_starts_with(
            (string) new \ReflectionClass($class)->getFileName(),
            \dirname(__DIR__) . \DIRECTORY_SEPARATOR,
        );
    }
}
