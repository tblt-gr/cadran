<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Accounts\Domain\Account;
use App\Module\Accounts\Domain\AccountValuationMode;
use App\Module\Catalog\Domain\AccountKind;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Portfolio\Domain\Valuation\CashSource;
use App\Module\Portfolio\Domain\Valuation\HeldPosition;
use App\Module\Portfolio\Domain\Valuation\PositionSnapshot;
use App\Module\Portfolio\Domain\Valuation\Valuation;
use App\Module\Portfolio\Domain\Valuation\ValuationCompleteness;
use App\Module\Portfolio\Domain\Valuation\ValuationDetail;
use App\Module\Portfolio\Domain\Valuation\ValuationRepository;

/**
 * Brings the valuation of the day back in step with the operations after a trade or a cash flow: what the latest
 * complete valuation knew is carried forward (prices keep their date), and only what the operation changes is
 * restated - the cash the operations now give, the quantity it moved and, for a new instrument, its purchase price.
 *
 * It never guesses. Without a complete valuation to carry, with a statement total of the owner, with holdings that
 * differ from the base by anything but the operation, or with a line it cannot price, nothing is recorded.
 */
final readonly class RefreshValuationAfterOperation
{
    public const string NOTE = 'Mise à jour automatique après une opération.';

    public function __construct(
        private ValuationRepository $valuations,
        private ValuationHoldings $holdings,
        private RecordPortfolioValuation $record,
        private WorkspaceCalendar $calendar,
    ) {
    }

    /**
     * @param array<string, DecimalValue> $quantityChanges instrument identifier => net change of quantity the operation brought
     * @param OperationPrice|null         $bought          the purchase just recorded: the only instrument that may be priced from an operation
     * @param OperationPrice|null         $replaced        the purchase a correction replaces: a base line priced from it takes the new price
     */
    public function __invoke(WorkspaceScope $workspace, Account $account, array $quantityChanges, ?OperationPrice $bought, ?OperationPrice $replaced = null): void
    {
        if (AccountKind::PORTFOLIO !== $account->kind || AccountValuationMode::PORTFOLIO !== $account->valuationMode || null !== $account->archivedAt || $account->isClosed()) {
            return;
        }
        $today = $this->calendar->today();
        $current = $this->valuations->findActive($workspace, $account->id, $today);
        $base = $current ?? $this->valuations->latestActiveBefore($workspace, $account->id, $today);
        // A statement total of the owner is not something an operation can restate.
        if (null === $base || ValuationCompleteness::COMPLETE !== $base->completeness || null === $base->securitiesValue || $base->withdrawn || null !== $base->observedTotal) {
            return;
        }
        [$held] = $this->holdings->held($workspace, $account->id, $today);
        if (!self::coherent($held, $base, $quantityChanges)) {
            return;
        }
        $derivedCash = $this->holdings->derivedCash($workspace, $account->id, $today);
        // An observed cash differing from the derived one is a statement the operations cannot replay: its gap is kept.
        $observedCash = CashSource::OBSERVED === $base->cashSource
            ? ExactDecimal::add($derivedCash, ExactDecimal::subtract($base->cash, $base->derivedCash))
            : null;
        $input = ValuationDetail::POSITIONS === $base->detail
            ? $this->positionsInput($account, $base, $current, $held, $derivedCash, $observedCash, $bought, $replaced)
            : ([] === $quantityChanges ? $this->totalInput($account, $base, $current, $derivedCash, $observedCash) : null);
        if (null !== $input) {
            ($this->record)($account->id, $input);
        }
    }

    /**
     * The holdings may differ from the base by the operation and by nothing else: otherwise an earlier, unrelated
     * change of history would be hidden by the refresh.
     *
     * @param list<HeldPosition>          $held
     * @param array<string, DecimalValue> $quantityChanges
     */
    private static function coherent(array $held, Valuation $base, array $quantityChanges): bool
    {
        $heldQuantities = [];
        foreach ($held as $position) {
            $heldQuantities[$position->instrumentId] = $position->quantity;
        }
        $baseQuantities = [];
        foreach ($base->positions as $snapshot) {
            $baseQuantities[$snapshot->instrumentId] = $snapshot->quantity;
        }
        foreach ([...array_keys($heldQuantities), ...array_keys($baseQuantities), ...array_keys($quantityChanges)] as $instrumentId) {
            $delta = ExactDecimal::subtract($heldQuantities[$instrumentId] ?? DecimalValue::zero(), $baseQuantities[$instrumentId] ?? DecimalValue::zero());
            if (0 !== $delta->compareTo($quantityChanges[$instrumentId] ?? DecimalValue::zero())) {
                return false;
            }
        }

        return true;
    }

    private function totalInput(Account $account, Valuation $base, ?Valuation $current, DecimalValue $derivedCash, ?DecimalValue $observedCash): ?ValuationInput
    {
        $securities = $base->securitiesValue ?? throw new \LogicException('A complete valuation states its securities.');
        $total = ExactDecimal::add($securities, $observedCash ?? $derivedCash);
        if (null !== $current && null !== $current->totalValue && 0 === $current->totalValue->compareTo($total) && $current->cashSource === $base->cashSource) {
            return null;
        }

        return new ValuationInput(
            valuedOn: $this->calendar->today()->format('Y-m-d'),
            detail: 'TOTAL',
            totalValue: new AmountInput($total->toString(), $account->assetCode->toString()),
            prices: null,
            observedCash: self::cash($account, $observedCash),
            observedTotal: null,
            note: self::NOTE,
            version: $current?->version,
        );
    }

    /** @param list<HeldPosition> $held */
    private function positionsInput(Account $account, Valuation $base, ?Valuation $current, array $held, DecimalValue $derivedCash, ?DecimalValue $observedCash, ?OperationPrice $bought, ?OperationPrice $replaced): ?ValuationInput
    {
        $carried = [];
        foreach ($base->positions as $snapshot) {
            $carried[$snapshot->instrumentId] = $snapshot;
        }
        $prices = [];
        foreach ($held as $position) {
            $snapshot = $carried[$position->instrumentId] ?? null;
            if (null !== $snapshot && null !== $bought && $bought->instrumentId === $position->instrumentId && self::pricedFrom($snapshot, $replaced)) {
                $prices[] = new PriceInput($position->instrumentId, $bought->unitPrice->toString(), $bought->tradeDate->format('Y-m-d'));
            } elseif (null !== $snapshot) {
                if (null === $snapshot->unitPrice || null === $snapshot->priceDate) {
                    return null;
                }
                $prices[] = new PriceInput($position->instrumentId, $snapshot->unitPrice->toString(), $snapshot->priceDate->format('Y-m-d'));
            } elseif (null !== $bought && $bought->instrumentId === $position->instrumentId) {
                $prices[] = new PriceInput($position->instrumentId, $bought->unitPrice->toString(), $bought->tradeDate->format('Y-m-d'));
            } else {
                return null;
            }
        }
        if (null !== $current && self::unchanged($current, $base, $held, $prices, $observedCash ?? $derivedCash)) {
            return null;
        }

        return new ValuationInput(
            valuedOn: $this->calendar->today()->format('Y-m-d'),
            detail: 'POSITIONS',
            totalValue: null,
            prices: $prices,
            observedCash: self::cash($account, $observedCash),
            observedTotal: null,
            note: self::NOTE,
            version: $current?->version,
        );
    }

    /** Whether the line got its price from the purchase being replaced, which no longer stands. */
    private static function pricedFrom(PositionSnapshot $snapshot, ?OperationPrice $replaced): bool
    {
        return null !== $replaced && $replaced->instrumentId === $snapshot->instrumentId && null !== $snapshot->unitPrice && null !== $snapshot->priceDate
            && 0 === $snapshot->unitPrice->compareTo($replaced->unitPrice) && $snapshot->priceDate == $replaced->tradeDate;
    }

    /**
     * Whether the valuation of the day already says what the operations say: a retried request records nothing twice.
     *
     * @param list<HeldPosition> $held
     * @param list<PriceInput>   $prices
     */
    private static function unchanged(Valuation $current, Valuation $base, array $held, array $prices, DecimalValue $cash): bool
    {
        if (ValuationDetail::POSITIONS !== $current->detail || $current->cashSource !== $base->cashSource || count($current->positions) !== count($held)) {
            return false;
        }
        $frozen = [];
        foreach ($current->positions as $snapshot) {
            $frozen[$snapshot->instrumentId] = $snapshot;
        }
        foreach ($held as $index => $position) {
            $snapshot = $frozen[$position->instrumentId] ?? null;
            if (!$snapshot instanceof PositionSnapshot || 0 !== $snapshot->quantity->compareTo($position->quantity)
                || $snapshot->unitPrice?->toString() !== self::canonical($prices[$index]->price)
                || $snapshot->priceDate?->format('Y-m-d') !== $prices[$index]->priceDate) {
                return false;
            }
        }

        return 0 === $current->cash->compareTo($cash);
    }

    private static function canonical(?string $price): ?string
    {
        return null === $price ? null : DecimalValue::fromString($price)->toString();
    }

    private static function cash(Account $account, ?DecimalValue $observedCash): ?AmountInput
    {
        return null === $observedCash ? null : new AmountInput($observedCash->toString(), $account->assetCode->toString());
    }
}
