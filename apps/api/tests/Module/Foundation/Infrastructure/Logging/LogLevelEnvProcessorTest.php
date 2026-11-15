<?php

declare(strict_types=1);

namespace App\Tests\Module\Foundation\Infrastructure\Logging;

use App\Module\Foundation\Infrastructure\Logging\LogLevelEnvProcessor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;

final class LogLevelEnvProcessorTest extends TestCase
{
    #[DataProvider('accepted')]
    public function testItAcceptsTheSupportedLevels(string $level): void
    {
        self::assertSame($level, (new LogLevelEnvProcessor())->getEnv('log_level', 'LOG_LEVEL', static fn (): string => $level));
    }

    /** @return iterable<string, array{string}> */
    public static function accepted(): iterable
    {
        foreach (LogLevelEnvProcessor::LEVELS as $level) {
            yield $level => [$level];
        }
    }

    #[DataProvider('refused')]
    public function testItRefusesAnythingElseWithAnExplicitMessage(string $level): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('LOG_LEVEL');
        (new LogLevelEnvProcessor())->getEnv('log_level', 'LOG_LEVEL', static fn (): string => $level);
    }

    /** @return iterable<string, array{string}> */
    public static function refused(): iterable
    {
        yield 'typo' => ['verbose'];
        yield 'uppercase' => ['DEBUG'];
        yield 'empty' => [''];
        yield 'more severe level' => ['critical'];
    }
}
