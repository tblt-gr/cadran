<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;

/**
 * Reads back what the application logs so a test can assert that a secret never reached a log record. The test
 * environment swaps the stderr handler for a TestHandler; each record is rendered by the JSON formatter the
 * deployed handler uses, so the assertions see exactly the line Docker would collect.
 */
final class LogCapture
{
    private const array LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    private function __construct(private readonly TestHandler $handler)
    {
    }

    public static function attach(object $logger): self
    {
        if (!$logger instanceof Logger) {
            throw new \LogicException('The test kernel is expected to use the Monolog logger.');
        }
        foreach ($logger->getHandlers() as $handler) {
            if ($handler instanceof TestHandler) {
                // Debug on purpose: the deployed level (notice) would hide the records a lowered LOG_LEVEL exposes.
                $handler->setLevel(Level::Debug);
                $handler->clear();

                return new self($handler);
            }
        }

        throw new \LogicException('The test logger has no TestHandler.');
    }

    public static function attachEveryRecord(object $logger): self
    {
        return self::attach($logger);
    }

    /**
     * @param string|null $atLeast a PSR-3 level: only records of that level or a more severe one are searched
     */
    public function contains(string $needle, ?string $atLeast = null): bool
    {
        $threshold = null === $atLeast ? 0 : array_search($atLeast, self::LEVELS, true);
        foreach ($this->records() as $record) {
            $level = array_search(strtolower($record['level_name']), self::LEVELS, true);
            if (false !== $threshold && false !== $level && $level >= $threshold && (str_contains($record['line'], $needle) || str_contains(str_replace('\\\\', '\\', $record['line']), $needle))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every captured record as the JSON object the stderr handler writes, one per element.
     *
     * @return list<array{line: string, level_name: string, data: array<string, mixed>}>
     */
    public function records(): array
    {
        $formatter = new JsonFormatter(JsonFormatter::BATCH_MODE_NEWLINES, true);
        $records = [];
        foreach ($this->handler->getRecords() as $record) {
            $line = trim($formatter->format($record));
            $data = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                throw new \UnexpectedValueException('A log line is not a JSON object.');
            }
            $typed = [];
            foreach ($data as $key => $value) {
                $typed[(string) $key] = $value;
            }
            $records[] = ['line' => $line, 'level_name' => $record->level->getName(), 'data' => $typed];
        }

        return $records;
    }
}
