<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

use App\Module\Accounts\Domain\CalendarMonth;
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
     * @param list<CalendarMonth> $months
     *
     * @return non-empty-list<ResolvedMetricPolicy>
     */
    public function forMonths(WorkspaceScope $workspace, array $months): array
    {
        if ([] === $months) {
            throw new \InvalidArgumentException('At least one month is required to resolve a metric policy.');
        }
        $activations = $this->policies->activations($workspace);
        $now = $this->clock->now();
        $resolved = [];
        foreach ($months as $month) {
            $version = MetricPolicyResolver::forMonth($this->closures->findActive($workspace, $month)?->closedAt, $now, $activations);
            $resolved[] = new ResolvedMetricPolicy($version, $this->policies->find($workspace, $version));
        }

        return $resolved;
    }
}
