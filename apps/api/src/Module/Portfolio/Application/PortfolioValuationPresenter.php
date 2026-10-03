<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Portfolio\Domain\InstrumentRepository;
use App\Module\Portfolio\Domain\Valuation\Valuation;
use App\Module\Portfolio\Domain\Valuation\ValuationFlag;
use App\Module\Reference\Application\AssetCatalog;

/** Adds what a stored valuation does not hold by itself: its instruments, its precision and the flags a replay raises. */
final readonly class PortfolioValuationPresenter
{
    public function __construct(
        private InstrumentRepository $instruments,
        private AssetCatalog $assets,
        private PositionsChangedDetector $changed,
    ) {
    }

    /**
     * @param list<Valuation> $valuations
     *
     * @return list<ValuationView>
     */
    public function present(WorkspaceScope $workspace, array $valuations): array
    {
        $ids = [];
        foreach ($valuations as $valuation) {
            foreach ($valuation->positions as $snapshot) {
                $ids[$snapshot->instrumentId] = true;
            }
        }
        $instruments = [];
        foreach ($this->instruments->findMany($workspace, array_keys($ids)) as $instrument) {
            $instruments[$instrument->id] = $instrument;
        }

        $views = [];
        foreach ($valuations as $valuation) {
            $precision = ($this->assets->findByCode($valuation->assetCode) ?? throw new \LogicException('A valuation asset is missing from the reference.'))->precision->display;
            $flags = $valuation->flags($precision);
            if ($valuation->isActive() && $this->changed->changed($workspace, $valuation)) {
                $flags[] = ValuationFlag::POSITIONS_CHANGED;
                usort($flags, static fn (ValuationFlag $left, ValuationFlag $right): int => array_search($left, ValuationFlag::cases(), true) <=> array_search($right, ValuationFlag::cases(), true));
            }
            $views[] = new ValuationView($valuation, $flags, $instruments, $precision);
        }

        return $views;
    }
}
