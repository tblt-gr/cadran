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
     * The non-zero quantities held at the end of each day from a single read and replay of the account's operations.
     *
     * @param list<\DateTimeImmutable> $days
     *
     * @return array<string, array<string, CalculatedDecimal>|null> keyed by `Y-m-d`, null from the first operation that cannot be replayed
     */
    public function quantitiesAt(WorkspaceScope $workspace, string $accountId, array $days): array
    {
        $operations = $this->operations->activeForAccount($workspace, $accountId);

        return $this->ledger->quantitiesAt($operations, $this->precisions($workspace, $operations), $days);
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
