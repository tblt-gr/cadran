<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Foundation\Domain\CalculatedDecimal;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Portfolio\Domain\Positions\PositionReplayFailed;
use App\Module\Portfolio\Domain\Valuation\Valuation;

/**
 * Compares the quantities frozen with a valuation to a fresh replay at its date. A difference means the history
 * was edited since: nothing is rewritten, the valuation is only flagged.
 */
final readonly class PositionsChangedDetector
{
    public function __construct(private ReconcilePortfolioPositions $reconcile)
    {
    }

    public function changed(WorkspaceScope $workspace, Valuation $valuation): bool
    {
        try {
            $replayed = ($this->reconcile)($workspace, $valuation->accountId, $valuation->valuedOn)->positions;
        } catch (PositionReplayFailed) {
            return true;
        }
        $current = [];
        foreach ($replayed as $instrumentId => $position) {
            if (!$position->quantity->isZero()) {
                $current[$instrumentId] = $position->quantity;
            }
        }
        $frozen = [];
        foreach ($valuation->positions as $snapshot) {
            $frozen[$snapshot->instrumentId] = $snapshot;
        }
        $currentIds = array_keys($current);
        $frozenIds = array_keys($frozen);
        sort($currentIds);
        sort($frozenIds);
        if ($currentIds !== $frozenIds) {
            return true;
        }
        foreach ($frozen as $instrumentId => $snapshot) {
            if (0 !== CalculatedDecimal::fromDecimal($snapshot->quantity)->compareTo($current[$instrumentId])) {
                return true;
            }
        }

        return false;
    }
}
