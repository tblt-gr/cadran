<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Foundation\Domain\CalculatedDecimal;
use App\Module\Foundation\Domain\WorkspaceScope;
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

    /**
     * One replay of the account's operations serves every valuation, whatever their number.
     *
     * @param list<Valuation> $valuations active valuations of the account
     *
     * @return array<string, bool> valuation identifier => the positions changed since
     */
    public function changedAmong(WorkspaceScope $workspace, string $accountId, array $valuations): array
    {
        if ([] === $valuations) {
            return [];
        }
        $held = $this->reconcile->quantitiesAt($workspace, $accountId, array_map(static fn (Valuation $valuation): \DateTimeImmutable => $valuation->valuedOn, $valuations));
        $changed = [];
        foreach ($valuations as $valuation) {
            $current = $held[$valuation->valuedOn->format('Y-m-d')] ?? null;
            $changed[$valuation->id] = null === $current || self::differs($valuation, $current);
        }

        return $changed;
    }

    /** Whether the account holds, at the end of $day, other quantities than the ones frozen with the valuation. */
    public function changedOn(WorkspaceScope $workspace, string $accountId, Valuation $valuation, \DateTimeImmutable $day): bool
    {
        $current = $this->reconcile->quantitiesAt($workspace, $accountId, [$day])[$day->format('Y-m-d')] ?? null;

        return null === $current || self::differs($valuation, $current);
    }

    /** @param array<string, CalculatedDecimal> $current non-zero replayed quantities by instrument */
    private static function differs(Valuation $valuation, array $current): bool
    {
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
