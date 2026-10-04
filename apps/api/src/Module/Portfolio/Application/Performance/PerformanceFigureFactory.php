<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application\Performance;

use App\Module\Portfolio\Domain\Cash\PortfolioCostPolicy;
use App\Module\Portfolio\Domain\Performance\PerformanceFinding;
use App\Module\Portfolio\Domain\Performance\PerformanceMethod;
use App\Module\Portfolio\Domain\Performance\PerformanceQuality;
use App\Module\Portfolio\Domain\Performance\PeriodAnchor;

/**
 * Assembles the explanation of a figure from the result of the calculator that produced its value, so the value, the
 * quality, the inputs and the freshness always describe the same computation. Freshness is informative only.
 */
final class PerformanceFigureFactory
{
    /**
     * @param array<string, mixed>                                $inputs   named values and constants, built by the calculator
     * @param list<PerformanceFinding>                            $findings
     * @param list<array{kind: string, id: string, date: string}> $sources
     */
    public function explain(
        PerformanceMethod $method,
        ?PeriodAnchor $start,
        ?PeriodAnchor $end,
        \DateTimeImmutable $today,
        array $inputs,
        PerformanceQuality $quality,
        array $findings,
        array $sources,
        int $sourceCount,
    ): PerformanceExplanation {
        return new PerformanceExplanation(
            $method, PerformanceMethod::VERSION, PortfolioCostPolicy::PERFORMANCE_BASIS,
            $start?->date->format('Y-m-d'), $end?->date->format('Y-m-d'),
            null === $start || null === $end ? null : (int) $start->date->diff($end->date)->format('%a'),
            $inputs,
            ['start' => self::freshness($start, $today), 'end' => self::freshness($end, $today)],
            $quality, $findings, $sources, $sourceCount,
        );
    }

    /** @return array{date: string, ageDays: int, priceQuality: ?string}|null */
    private static function freshness(?PeriodAnchor $anchor, \DateTimeImmutable $today): ?array
    {
        if (null === $anchor) {
            return null;
        }

        // An anchor dated after today (a future valuation) has an age of 0, never a negative one.
        return [
            'date' => $anchor->date->format('Y-m-d'),
            'ageDays' => max(0, (int) $anchor->date->diff($today)->format('%r%a')),
            'priceQuality' => self::priceQuality($anchor),
        ];
    }

    private static function priceQuality(PeriodAnchor $anchor): ?string
    {
        if (null === $anchor->detail) {
            return null;
        }
        if ('TOTAL' === $anchor->detail) {
            return 'NOT_DETAILED';
        }

        return in_array('STALE_PRICES', $anchor->flags, true) ? 'STALE' : 'CURRENT';
    }
}
