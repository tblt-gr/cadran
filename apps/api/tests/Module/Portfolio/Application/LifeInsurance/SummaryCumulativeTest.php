<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\Application\LifeInsurance;

use App\Module\Portfolio\Application\LifeInsurance\SummaryCumulative;
use App\Module\Portfolio\Domain\LifeInsurance\IncomeAndFeesCalculator;
use PHPUnit\Framework\TestCase;

final class SummaryCumulativeTest extends TestCase
{
    public function testAnnualSourcesAndIssuesKeepExactCountsWithBoundedPreviews(): void
    {
        $first = IncomeAndFeesCalculator::calculate(2025, 'EUR', [], []);
        $first['sourceCount'] = 200;
        $first['sources'] = array_fill(0, 20, ['kind' => 'OPERATION', 'id' => 'first', 'date' => '2025-12-31']);
        $first['qualityIssues'] = [['code' => 'INTEREST_BASIS_UNKNOWN', 'count' => 40, 'operationIds' => array_fill(0, 20, 'first')]];
        $last = $first;
        $last['year'] = 2026;
        $last['sourceCount'] = 100;
        $last['qualityIssues'][0]['count'] = 100;
        $summary = SummaryCumulative::of([], [$first, $last]);
        self::assertSame(300, $summary['sourceCount']);
        self::assertIsArray($summary['sources']);
        self::assertCount(20, $summary['sources']);
        $issues = SummaryCumulative::issues([$first, $last]);
        self::assertSame(140, $issues[0]['count']);
        self::assertCount(20, $issues[0]['operationIds']);
    }
}
