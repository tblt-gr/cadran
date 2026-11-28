<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Accounts\Domain\PeriodClosure;
use App\Module\Accounts\Domain\PeriodClosureRepository;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Reporting\Domain\MetricPolicyRepository;
use App\Module\Reporting\Domain\MetricPolicyResolver;
use Symfony\Component\Clock\ClockInterface;

/**
 * Resolves the policy of one or several months: a closed month keeps the
 * version active at its closing instant, an open month the version active now.
 * A version that cannot be loaded yields no definition, never version 1.
 */
final readonly class ResolveMetricPolicy
{
    public function __construct(
        private MetricPolicyRepository $policies,
        private PeriodClosureRepository $closures,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(WorkspaceScope $workspace, CalendarMonth $month): ResolvedMetricPolicy
    {
        return $this->forMonths($workspace, [$month])[0];
    }

    /**
     * Reads the activations once and each definition once, whatever the number of months.
     *
     * @param list<CalendarMonth>               $months
     * @param array<string, PeriodClosure>|null $closuresByMonthKey the active closures the caller already read, keyed
     *                                                              by `YYYY-MM`; a month absent from it is open
     *
     * @return non-empty-list<ResolvedMetricPolicy>
     */
    public function forMonths(WorkspaceScope $workspace, array $months, ?array $closuresByMonthKey = null): array
    {
        if ([] === $months) {
            throw new \InvalidArgumentException('At least one month is required to resolve a metric policy.');
        }
        $closuresByMonthKey ??= $this->closuresOf($workspace, $months);
        $activations = $this->policies->activations($workspace);
        $now = $this->clock->now();
        $definitions = [];
        $resolved = [];
        foreach ($months as $month) {
            $version = MetricPolicyResolver::forMonth(($closuresByMonthKey[$month->key()] ?? null)?->closedAt, $now, $activations);
            if (!array_key_exists($version, $definitions)) {
                $definitions[$version] = $this->policies->find($workspace, $version);
            }
            $resolved[] = new ResolvedMetricPolicy($version, $definitions[$version]);
        }

        return $resolved;
    }

    /**
     * @param non-empty-list<CalendarMonth> $months
     *
     * @return array<string, PeriodClosure>
     */
    private function closuresOf(WorkspaceScope $workspace, array $months): array
    {
        $first = $months[0];
        $last = $months[0];
        foreach ($months as $month) {
            $first = $month->key() < $first->key() ? $month : $first;
            $last = $month->key() > $last->key() ? $month : $last;
        }
        $closures = [];
        foreach ($this->closures->activeClosuresBetween($workspace, $first, $last) as $closure) {
            $closures[$closure->month->key()] = $closure;
        }

        return $closures;
    }
}
