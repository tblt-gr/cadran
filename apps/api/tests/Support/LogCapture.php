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

    public function contains(string $needle): bool
    {
        rewind($this->stream);

        return str_contains((string) stream_get_contents($this->stream), $needle);
    }

    private static function assertLogger(object $logger): void
    {
        if (!$logger instanceof Logger) {
            throw new \LogicException('The test kernel is expected to use the framework logger.');
        }
    }
}
