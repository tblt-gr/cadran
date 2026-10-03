<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\Application;

use App\Module\Portfolio\Application\PortfolioExplanation;
use PHPUnit\Framework\TestCase;

final class PortfolioExplanationTest extends TestCase
{
    public function testTruncationKeepsBothValuationSourcesEvenWithManyNewerOperations(): void
    {
        $sources = [
            ['kind' => 'VALUATION', 'id' => 'complete', 'date' => '2026-09-30'],
            ['kind' => 'VALUATION', 'id' => 'partial', 'date' => '2026-10-03'],
        ];
        for ($i = 1; $i <= 55; ++$i) {
            $sources[] = ['kind' => 'OPERATION', 'id' => (string) $i, 'date' => '2026-10-02'];
        }
        $explanation = PortfolioExplanation::of('2026-01-01', '2026-10-03', 'NEEDS_REVIEW', null, $sources);
        self::assertCount(50, $explanation['sources']);
        self::assertSame(7, $explanation['truncatedSourceCount']);
        self::assertSame('partial', $explanation['sources'][0]['id']);
        self::assertSame('complete', $explanation['sources'][1]['id']);
    }
}
