<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Accounts\Domain\Account;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Portfolio\Domain\Valuation\CashSource;
use App\Module\Portfolio\Domain\Valuation\Valuation;
use App\Module\Portfolio\Domain\Valuation\ValuationCompleteness;
use App\Module\Portfolio\Domain\Valuation\ValuationRepository;

/**
 * Brings the valuation of the day back in step with the cash after an external cash flow: the securities of the
 * latest complete valuation are kept as they were, and the cash is the one the operations now give.
 *
 * It only ever follows cash. When the positions changed since that valuation, or no complete valuation exists, the
 * securities cannot be known without a price, so nothing is recorded rather than a guessed figure.
 */
final readonly class RefreshValuationAfterCashFlow
{
    public const string NOTE = 'Mise à jour automatique après un virement.';

    public function __construct(
        private ValuationRepository $valuations,
        private ValuationHoldings $holdings,
        private PositionsChangedDetector $positionsChanged,
        private RecordPortfolioValuation $record,
        private WorkspaceCalendar $calendar,
    ) {
    }

    public function __invoke(WorkspaceScope $workspace, Account $account): void
    {
        $today = $this->calendar->today();
        $current = $this->valuations->findActive($workspace, $account->id, $today);
        $base = $current ?? $this->valuations->latestActiveBefore($workspace, $account->id, $today);
        // The securities are carried forward only while today's holdings are the ones the valuation froze, and
        // a statement total of the owner is not something a cash flow can restate.
        if (null === $base || !self::usable($base) || null !== $base->observedTotal || $this->positionsChanged->changedOn($workspace, $account->id, $base, $today)) {
            return;
        }
        $securities = $base->securitiesValue ?? throw new \LogicException('A complete valuation states its securities.');
        $derivedCash = $this->holdings->derivedCash($workspace, $account->id, $today);
        // An observed cash differing from the derived one is a statement the operations cannot replay: its gap is kept.
        $observedCash = CashSource::OBSERVED === $base->cashSource
            ? ExactDecimal::add($derivedCash, ExactDecimal::subtract($base->cash, $base->derivedCash))
            : null;
        $total = ExactDecimal::add($securities, $observedCash ?? $derivedCash);
        if (null !== $current && null !== $current->totalValue && 0 === $current->totalValue->compareTo($total) && $current->cashSource === $base->cashSource) {
            return;
        }

        ($this->record)($account->id, new ValuationInput(
            valuedOn: $today->format('Y-m-d'),
            detail: 'TOTAL',
            totalValue: new AmountInput($total->toString(), $account->assetCode->toString()),
            prices: null,
            observedCash: null === $observedCash ? null : new AmountInput($observedCash->toString(), $account->assetCode->toString()),
            observedTotal: null,
            note: self::NOTE,
            version: $current?->version,
        ));
    }

    private static function usable(Valuation $valuation): bool
    {
        return ValuationCompleteness::COMPLETE === $valuation->completeness && null !== $valuation->securitiesValue && !$valuation->withdrawn;
    }
}
