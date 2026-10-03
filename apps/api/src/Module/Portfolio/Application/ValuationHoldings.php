<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\MalformedDecimal;
use App\Module\Foundation\Domain\PrecisionExceeded;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Portfolio\Domain\Cash\CashLedger;
use App\Module\Portfolio\Domain\Instrument;
use App\Module\Portfolio\Domain\InstrumentRepository;
use App\Module\Portfolio\Domain\InvalidPortfolioOperation;
use App\Module\Portfolio\Domain\PortfolioOperationRepository;
use App\Module\Portfolio\Domain\Valuation\HeldPosition;

/** What the operations say an account held and owned in cash on a day: the base every valuation is checked against. */
final readonly class ValuationHoldings
{
    public function __construct(
        private ReconcilePortfolioPositions $positions,
        private PortfolioOperationRepository $operations,
        private InstrumentRepository $instruments,
    ) {
    }

    /**
     * @return array{list<HeldPosition>, array<string, Instrument>} the non-zero positions by instrument identifier, and their instruments
     */
    public function held(WorkspaceScope $workspace, string $accountId, \DateTimeImmutable $day): array
    {
        $held = [];
        foreach (($this->positions)($workspace, $accountId, $day)->positions as $instrumentId => $position) {
            if ($position->quantity->isZero()) {
                continue;
            }
            $held[$instrumentId] = new HeldPosition($instrumentId, self::stored($position->quantity->toString()) ?? throw new InvalidPortfolioOperation('A held quantity is too large to be valued exactly.', 'invalid_valuation_total'), self::stored($position->averageCostBasis?->toString()));
        }
        ksort($held);
        $instruments = [];
        foreach ($this->instruments->findMany($workspace, array_keys($held)) as $instrument) {
            $instruments[$instrument->id] = $instrument;
        }

        return [array_values($held), $instruments];
    }

    public function derivedCash(WorkspaceScope $workspace, string $accountId, \DateTimeImmutable $day): DecimalValue
    {
        return CashLedger::replay([], $this->operations->activeForAccount($workspace, $accountId, $day))->closingBalance;
    }

    /** A replayed figure beyond the storable width is shown as unknown rather than truncated. */
    private static function stored(?string $literal): ?DecimalValue
    {
        if (null === $literal) {
            return null;
        }
        try {
            return DecimalValue::fromString($literal);
        } catch (MalformedDecimal|PrecisionExceeded) {
            return null;
        }
    }
}
