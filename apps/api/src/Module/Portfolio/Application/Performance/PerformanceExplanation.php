<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application\Performance;

use App\Module\Portfolio\Domain\Performance\PerformanceFinding;
use App\Module\Portfolio\Domain\Performance\PerformanceMethod;
use App\Module\Portfolio\Domain\Performance\PerformanceQuality;

/** What explains one performance figure: its method, period, inputs, anchor freshness, quality, reasons and sources. */
final readonly class PerformanceExplanation
{
    /**
     * @param array<string, mixed>                                                                                                                    $inputs
     * @param array{start: ?array{date: string, ageDays: int, priceQuality: ?string}, end: ?array{date: string, ageDays: int, priceQuality: ?string}} $freshness
     * @param list<PerformanceFinding>                                                                                                                $findings
     * @param list<array{kind: string, id: string, date: string}>                                                                                     $sources
     */
    public function __construct(
        public PerformanceMethod $method,
        public string $methodVersion,
        public string $costPolicy,
        public ?string $from,
        public ?string $to,
        public ?int $days,
        public array $inputs,
        public array $freshness,
        public PerformanceQuality $quality,
        public array $findings,
        public array $sources,
        public int $sourceCount,
    ) {
    }
}
