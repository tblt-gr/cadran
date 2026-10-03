<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Portfolio\Domain\InstrumentRepository;
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
        $operations = $this->operations->activeForAccount($workspace, $accountId, $asOf);
        $ids = [];
        foreach ($operations as $operation) {
            foreach ([$operation->details->instrumentId, $operation->details->counterInstrumentId] as $id) {
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

        return $this->ledger->replay($operations, $precisions);
    }
}
