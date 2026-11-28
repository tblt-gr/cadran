<?php

declare(strict_types=1);

namespace App\Tests\Module\Foundation\Infrastructure\Logging;

use App\Module\Foundation\Infrastructure\Logging\SafeExceptionContext;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\TestCase;

final class SafeExceptionContextTest extends TestCase
{
    public function testAPlainExceptionIsDescribedByItsClassAndLocationOnly(): void
    {
        $exception = new \RuntimeException('1234.56 EUR');
        $line = __LINE__ - 1;

        self::assertSame(
            ['exception_class' => \RuntimeException::class, 'location' => 'SafeExceptionContextTest.php:'.$line],
            SafeExceptionContext::of($exception),
        );
    }

    public function testAWrappedDatabaseErrorAddsItsSqlState(): void
    {
        $driver = new class('Failing row contains (1234.56)', '25P02') extends AbstractException {};
        $exception = new \RuntimeException('wrapper', previous: new DriverException($driver, null));

        $context = SafeExceptionContext::of($exception);

        self::assertSame(['exception_class', 'location', 'sqlstate'], array_keys($context));
        self::assertSame(\RuntimeException::class, $context['exception_class']);
        self::assertSame('25P02', $context['sqlstate'] ?? null);
    }
}
