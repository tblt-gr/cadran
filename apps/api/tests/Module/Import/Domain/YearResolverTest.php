<?php

declare(strict_types=1);

namespace App\Tests\Module\Import\Domain;

use App\Module\Import\Domain\Workbook\YearResolver;
use App\Module\Import\Domain\WorkbookUsageError;
use PHPUnit\Framework\TestCase;

final class YearResolverTest extends TestCase
{
    public function testTheYearComesFromTheLeadingDigitsOfTheFileName(): void
    {
        self::assertSame(2025, YearResolver::resolve(null, '/data/2025 sample.xlsx'));
        self::assertSame(2023, YearResolver::resolve(null, '2023.xlsx'));
    }

    public function testAnExplicitYearWins(): void
    {
        self::assertSame(2024, YearResolver::resolve(2024, '/data/2025_sample.xlsx'));
        self::assertSame(2024, YearResolver::resolve(2024, '/data/budget.xlsx'));
    }

    public function testAFileNameWithoutAYearIsAUsageError(): void
    {
        $this->expectException(WorkbookUsageError::class);

        YearResolver::resolve(null, '/data/budget-2025.xlsx');
    }

    public function testAnImplausibleYearIsAUsageError(): void
    {
        $this->expectException(WorkbookUsageError::class);

        YearResolver::resolve(1850, 'x.xlsx');
    }
}
