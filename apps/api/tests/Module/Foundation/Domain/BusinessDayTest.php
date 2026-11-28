<?php

declare(strict_types=1);

namespace App\Tests\Module\Foundation\Domain;

use App\Module\Foundation\Domain\BusinessDay;
use App\Module\Foundation\Domain\InvalidBusinessDay;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BusinessDayTest extends TestCase
{
    public function testItReadsTheLastDayItCoversAtUtcMidnight(): void
    {
        self::assertSame('2100-12-31T00:00:00+00:00', BusinessDay::fromIsoDate('2100-12-31')->date->format(\DATE_ATOM));
    }

    #[DataProvider('refused')]
    public function testItRefusesAnythingThatIsNotABusinessDay(string $value): void
    {
        $this->expectException(InvalidBusinessDay::class);

        BusinessDay::fromIsoDate($value);
    }

    /** @return iterable<string, array{string}> */
    public static function refused(): iterable
    {
        yield 'overflowing day' => ['2026-02-30'];
        yield 'unpadded' => ['2026-2-3'];
        yield 'before the first day' => ['1899-12-31'];
        yield 'after the last day' => ['2101-01-01'];
    }
}
