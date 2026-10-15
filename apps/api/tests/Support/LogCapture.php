<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\HttpKernel\Log\Logger;

/**
 * Redirects the kernel logger's output into memory so a test can assert that a secret never reached a log record.
 * The service is already built when a test runs, hence the stream swap rather than a replacement.
 */
final class LogCapture
{
    private const array LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    /** @param resource $stream */
    private function __construct(private $stream)
    {
    }

    public static function attach(object $logger): self
    {
        self::assertLogger($logger);
        $stream = fopen('php://memory', 'w+');
        if (false === $stream) {
            throw new \RuntimeException('The in-memory log stream cannot be opened.');
        }
        $property = new \ReflectionProperty(Logger::class, 'handle');
        $property->setValue($logger, $stream);

        return new self($stream);
    }

    /**
     * Captures every level and, unlike the default formatter, the whole context of each record:
     * the default one only prints the message, which would hide a secret passed as context.
     */
    public static function attachEveryRecord(object $logger): self
    {
        $capture = self::attach($logger);
        (new \ReflectionProperty(Logger::class, 'minLevelIndex'))->setValue($logger, 0);
        (new \ReflectionProperty(Logger::class, 'formatter'))->setValue(
            $logger,
            static fn (string $level, string $message, array $context): string => sprintf(
                '[%s] %s %s',
                $level,
                $message,
                json_encode(self::normalize($context), JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ),
        );

        return $capture;
    }

    /**
     * @param string|null $atLeast a PSR-3 level: only records of that level or a more severe one are searched
     */
    public function contains(string $needle, ?string $atLeast = null): bool
    {
        rewind($this->stream);
        $content = (string) stream_get_contents($this->stream);
        if (null === $atLeast) {
            return str_contains($content, $needle);
        }

        $threshold = array_search($atLeast, self::LEVELS, true);
        foreach (explode(\PHP_EOL, $content) as $record) {
            $level = 1 === preg_match('/^\[([a-z]+)\]/', $record, $matches) ? array_search($matches[1], self::LEVELS, true) : false;
            if (false !== $threshold && false !== $level && $level >= $threshold && str_contains($record, $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function normalize(mixed $value): mixed
    {
        return match (true) {
            is_array($value) => array_map(self::normalize(...), $value),
            $value instanceof \Throwable => [$value::class, $value->getMessage(), $value->getFile(), $value->getLine(), $value->getTraceAsString(), self::normalize($value->getPrevious())],
            $value instanceof \Stringable => (string) $value,
            is_object($value) => $value::class,
            default => $value,
        };
    }

    private static function assertLogger(object $logger): void
    {
        if (!$logger instanceof Logger) {
            throw new \LogicException('The test kernel is expected to use the framework logger.');
        }
    }
}
