<?php

declare(strict_types=1);

namespace App\Tests\Module\Foundation\Infrastructure\Persistence;

use App\Module\Foundation\Infrastructure\Persistence\DbalValue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DbalValueTest extends TestCase
{
    public function testItNarrowsTheShapesPostgresqlYields(): void
    {
        self::assertSame('12', DbalValue::text(12));
        self::assertNull(DbalValue::nullableText(null));
        self::assertTrue(DbalValue::boolean('t'));
        self::assertTrue(DbalValue::boolean(1));
        self::assertFalse(DbalValue::boolean('f'));
        self::assertFalse(DbalValue::boolean(false));
        self::assertSame(-3, DbalValue::integer('-3'));
        self::assertSame('2026-02-28T00:00:00+00:00', DbalValue::businessDay('2026-02-28')->format(\DATE_ATOM));
        self::assertSame('2026-02-28T10:00:00+02:00', DbalValue::instant('2026-02-28 10:00:00+02')->format(\DATE_ATOM));
        self::assertNull(DbalValue::nullableInstant(null));
    }

    /** @param \Closure(): mixed $read */
    #[DataProvider('corrupt')]
    public function testACorruptValueRaisesRatherThanBeingDefaulted(\Closure $read): void
    {
        $this->expectException(\UnexpectedValueException::class);

        $read();
    }

    /** @return iterable<string, array{\Closure(): mixed}> */
    public static function corrupt(): iterable
    {
        yield 'array' => [static fn (): string => DbalValue::text([])];
        yield 'null text' => [static fn (): string => DbalValue::text(null)];
        yield 'null boolean' => [static fn (): bool => DbalValue::boolean(null)];
        yield 'unknown boolean' => [static fn (): bool => DbalValue::boolean('yes')];
        yield 'decimal integer' => [static fn (): int => DbalValue::integer('1.5')];
        yield 'null integer' => [static fn (): int => DbalValue::integer(null)];
        yield 'timestamp as day' => [static fn (): \DateTimeImmutable => DbalValue::businessDay('2026-02-28 10:00:00')];
        yield 'empty instant' => [static fn (): \DateTimeImmutable => DbalValue::instant('')];
        yield 'null instant' => [static fn (): \DateTimeImmutable => DbalValue::instant(null)];
    }
}
