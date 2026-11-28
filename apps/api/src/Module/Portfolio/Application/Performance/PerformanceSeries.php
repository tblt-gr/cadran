<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application\Performance;

use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Portfolio\Application\LoadedOperationHistory;
use App\Module\Portfolio\Domain\Performance\PerformanceHistoryStart;
use App\Module\Portfolio\Domain\Performance\PerformancePeriod;
use App\Module\Portfolio\Domain\PortfolioOperation;

/**
 * What one request knows of one account's performance: its bounded history, read once, and every period already
 * resolved from it. It is built per request and passed along, never held by a service, so nothing it remembers can
 * answer for another request, account or workspace.
 */
final class PerformanceSeries
{
    /** @var array<string, PerformancePeriodData> */
    private array $periods = [];

    /** The same history with the replays already made of it. */
    public readonly LoadedOperationHistory $loaded;

    /** @param list<PortfolioOperation> $history the active operations of the account, already bounded */
    public function __construct(
        private readonly WorkspaceScope $workspace,
        private readonly string $accountId,
        public readonly array $history,
    ) {
        $this->loaded = new LoadedOperationHistory($history);
    }

    /** @param \Closure(): PerformancePeriodData $resolve */
    public function period(WorkspaceScope $workspace, string $accountId, PerformancePeriod $period, PerformanceHistoryStart $historyStart, \Closure $resolve): PerformancePeriodData
    {
        if ($workspace->id !== $this->workspace->id || $accountId !== $this->accountId) {
            throw new \LogicException('A performance series serves one account of one workspace.');
        }

        return $this->periods[$period->scope->value.'|'.($period->label ?? '').'|'.$historyStart->name] ??= $resolve();
    }
}
