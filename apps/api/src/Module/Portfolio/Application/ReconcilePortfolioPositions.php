<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Foundation\Domain\CalculatedDecimal;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Portfolio\Domain\InstrumentRepository;
use App\Module\Portfolio\Domain\PortfolioOperation;
use App\Module\Portfolio\Domain\PortfolioOperationRepository;
use App\Module\Portfolio\Domain\Positions\PositionLedger;
use App\Module\Portfolio\Domain\Positions\PositionLedgerResult;
use App\Module\Portfolio\Domain\Positions\PositionReplayFailed;

final readonly class ReconcilePortfolioPositions
{
    public function __construct(
        private PortfolioOperationRepository $operations,
        private InstrumentRepository $instruments,
        private PositionLedger $ledger,
    ) {
    }

    public function __invoke(WorkspaceScope $workspace, string $accountId, ?\DateTimeImmutable $asOf = null): PositionLedgerResult
    {
        return $this->replayOf($workspace, $this->operations->activeForAccount($workspace, $accountId, $asOf));
    }

    /** @param list<PortfolioOperation> $operations already read active operations of the account */
    public function replayOf(WorkspaceScope $workspace, array $operations): PositionLedgerResult
    {
        return $this->ledger->replay($operations, $this->precisions($workspace, $operations));
    }

    /**
     * A ledger to read the released costs of `$prefix`, the first operations of the loaded history: a release never
     * depends on a later operation, so the replay of the whole history serves every period. Its positions are those at
     * the end of the whole history, not of the prefix.
     *
     * @param list<PortfolioOperation> $prefix
     */
    public function releasedCostsOf(WorkspaceScope $workspace, LoadedOperationHistory $loaded, array $prefix): PositionLedgerResult
    {
        try {
            return $loaded->ledger ??= $this->replayOf($workspace, $loaded->operations);
        } catch (PositionReplayFailed) {
            // A later operation cannot be replayed: it must not take down the periods before it.
            return $this->replayOf($workspace, $prefix);
        }
    }

    /**
     * The non-zero quantities held at the end of each day from a single read and replay of the account's operations.
     *
     * @param list<\DateTimeImmutable>    $days
     * @param LoadedOperationHistory|null $loaded the history of the account when already read, replayed once whatever the calls
     *
     * @return array<string, array<string, CalculatedDecimal>|null> keyed by `Y-m-d`, null from the first operation that cannot be replayed
     */
    public function quantitiesAt(WorkspaceScope $workspace, string $accountId, array $days, ?LoadedOperationHistory $loaded = null): array
    {
        if (null === $loaded) {
            $operations = $this->operations->activeForAccount($workspace, $accountId);

            return $this->ledger->quantitiesAt($operations, $this->precisions($workspace, $operations), $days);
        }
        $loaded->quantitiesByTradeDay ??= $this->ledger->quantitiesAt(
            $loaded->operations,
            $this->precisions($workspace, $loaded->operations),
            array_map(static fn (PortfolioOperation $operation): \DateTimeImmutable => $operation->details->tradeDate, $loaded->operations),
        );
        $tradeDays = array_keys($loaded->quantitiesByTradeDay);
        $held = [];
        foreach ($days as $day) {
            $key = $day->format('Y-m-d');
            $last = self::lastOnOrBefore($tradeDays, $key);
            $held[$key] = null === $last ? [] : $loaded->quantitiesByTradeDay[$last];
        }

        return $held;
    }

    /** @param list<string> $sortedDays */
    private static function lastOnOrBefore(array $sortedDays, string $day): ?string
    {
        $low = 0;
        $high = count($sortedDays) - 1;
        $found = null;
        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);
            if ($sortedDays[$middle] <= $day) {
                $found = $sortedDays[$middle];
                $low = $middle + 1;
            } else {
                $high = $middle - 1;
            }
        }

        return $found;
    }

    /**
     * @param list<PortfolioOperation> $operations
     *
     * @return array<string, int>
     */
    private function precisions(WorkspaceScope $workspace, array $operations): array
    {
        $ids = [];
        foreach ($operations as $operation) {
            foreach ([$operation->details->instrumentId, $operation->details->counterInstrumentId, ...array_map(static fn ($leg): string => $leg->instrumentId, $operation->details->legs)] as $id) {
                if (null !== $id) {
                    $ids[$id] = true;
                }
            }
        }
        $precisions = [];
        foreach ($this->instruments->findMany($workspace, array_keys($ids)) as $instrument) {
            $precisions[$instrument->id] = $instrument->quantityPrecision;
        }
        if (count($precisions) !== count($ids)) {
            throw new \LogicException('An active portfolio operation refers to a missing workspace instrument.');
        }

        return $precisions;
    }
}
