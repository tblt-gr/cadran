<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Foundation\Domain\CalculatedDecimal;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Portfolio\Application\Performance\PerformancePeriodBuilder;
use App\Module\Portfolio\Application\Performance\PerformanceRangeTooDense;
use App\Module\Portfolio\Domain\OperationType;
use App\Module\Portfolio\Domain\Performance\PerformanceAmount;
use App\Module\Portfolio\Domain\Performance\RealizedContribution;
use App\Module\Portfolio\Domain\PortfolioOperation;
use App\Module\Portfolio\Domain\Positions\PositionReplayFailed;
use App\Module\Reference\Application\AssetCatalog;

/**
 * One bounded replay of each account, including the other instruments needed to release its costs.
 *
 * @phpstan-import-type AccountLine from HoldingRow
 *
 * @phpstan-type HistoryRow array{accountId: string, label: string, quantity: ?string, value: ?string, unrealizedGain: ?string, realizedGain: ?string, income: ?string, feesAndTaxes: ?string, operationCount: ?int, firstOperationOn: ?string, lastOperationOn: ?string, reason: ?string, endsEmpty: bool}
 */
final readonly class InstrumentAccountHistoryReader
{
    public function __construct(
        private PortfolioAccountGate $gate,
        private PerformancePeriodBuilder $periods,
        private ReconcilePortfolioPositions $positions,
        private AssetCatalog $assets,
    ) {
    }

    /**
     * @param AccountLine|null $line
     *
     * @return HistoryRow
     */
    public function read(WorkspaceScope $workspace, string $accountId, string $label, string $instrumentId, ?array $line): array
    {
        $base = ['accountId' => $accountId, 'label' => $label, 'quantity' => $line['quantity'] ?? null,
            'value' => $line['value'] ?? null, 'unrealizedGain' => null === ($line['unrealizedGain'] ?? null) ? null : InstrumentHoldingAmounts::canonical($line['unrealizedGain'])];
        $account = $this->gate->forRead($workspace, $accountId);
        try {
            $history = $this->periods->history($workspace, $account);
        } catch (PerformanceRangeTooDense) {
            return self::unavailable($base, 'HISTORY_TOO_LARGE');
        }
        try {
            $ledger = $this->positions->replayOf($workspace, $history);
        } catch (PositionReplayFailed) {
            return self::unavailable($base, 'UNKNOWN_LOT_COST');
        }
        $matching = array_values(array_filter($history, static fn (PortfolioOperation $operation): bool => self::references($operation, $instrumentId)));
        $realized = $income = $fees = CalculatedDecimal::zero();
        foreach ($matching as $operation) {
            $contribution = RealizedContribution::of($operation, $ledger, $instrumentId);
            $realized = null === $realized || null === $contribution ? null : $realized->add($contribution);
            $details = $operation->details;
            if ($details->instrumentId !== $instrumentId) {
                continue;
            }
            if (in_array($details->type, [OperationType::DIVIDEND, OperationType::INTEREST, OperationType::EURO_FUND_INTEREST, OperationType::PROFIT_SHARING], true) && null !== $details->grossAmount) {
                $income = $income->add(CalculatedDecimal::fromDecimal($details->grossAmount));
            }
            if (OperationType::BUY !== $details->type) {
                foreach ([$details->fees, $details->taxes] as $cost) {
                    if (null !== $cost) {
                        $fees = $fees->subtract(CalculatedDecimal::fromDecimal($cost));
                    }
                }
            }
        }
        $precision = ($this->assets->findByCode($account->assetCode) ?? throw new \LogicException('An account asset is missing.'))->precision->display;
        $dates = array_map(static fn (PortfolioOperation $operation): string => $operation->details->tradeDate->format('Y-m-d'), $matching);

        return [...$base, 'realizedGain' => null === $realized ? null : PerformanceAmount::canonical($realized, $precision),
            'income' => PerformanceAmount::canonical($income, $precision), 'feesAndTaxes' => PerformanceAmount::canonical($fees, $precision),
            'operationCount' => count($matching), 'firstOperationOn' => [] === $dates ? null : min($dates), 'lastOperationOn' => [] === $dates ? null : max($dates),
            'reason' => null === $realized ? 'UNKNOWN_LOT_COST' : null,
            'endsEmpty' => !isset($ledger->positions[$instrumentId]) || $ledger->positions[$instrumentId]->quantity->isZero()];
    }

    private static function references(PortfolioOperation $operation, string $id): bool
    {
        $details = $operation->details;
        if ($id === $details->instrumentId || $id === $details->counterInstrumentId) {
            return true;
        }
        foreach ($details->legs as $leg) {
            if ($id === $leg->instrumentId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{accountId: string, label: string, quantity: ?string, value: ?string, unrealizedGain: ?string} $base
     *
     * @return HistoryRow
     */
    private static function unavailable(array $base, string $reason): array
    {
        return [...$base, 'quantity' => null, 'value' => null, 'unrealizedGain' => null, 'realizedGain' => null,
            'income' => null, 'feesAndTaxes' => null, 'operationCount' => null, 'firstOperationOn' => null, 'lastOperationOn' => null,
            'reason' => $reason, 'endsEmpty' => false];
    }
}
