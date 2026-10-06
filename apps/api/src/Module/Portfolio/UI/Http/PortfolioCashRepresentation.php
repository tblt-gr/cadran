<?php

declare(strict_types=1);

namespace App\Module\Portfolio\UI\Http;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Portfolio\Application\PortfolioCashView;
use App\Module\Portfolio\Domain\Cash\CashMovement;
use App\Module\Portfolio\Domain\Cash\CashWarning;
use App\Module\Portfolio\Domain\Cash\PortfolioCostPolicy;

final class PortfolioCashRepresentation
{
    /** @return array<string, mixed> */
    public static function of(PortfolioCashView $view): array
    {
        $asset = $view->assetCode;
        $amount = static fn (DecimalValue $value): array => ['value' => $value->toString(), 'assetCode' => $asset];
        $totals = $view->ledger->totals;

        return [
            'from' => $view->from,
            'to' => $view->to,
            'assetCode' => $asset,
            'openingBalance' => $amount($view->ledger->openingBalance),
            'closingBalance' => $amount($view->ledger->closingBalance),
            'currentBalance' => $amount($view->currentBalance),
            'totals' => [
                'supportsBought' => $amount($totals->supportsBought),
                'supportsSold' => $amount($totals->supportsSold),
                'deposits' => $amount($totals->deposits),
                'withdrawals' => $amount($totals->withdrawals),
                'buysGross' => $amount($totals->buysGross),
                'sellsGross' => $amount($totals->sellsGross),
                'dividendsGross' => $amount($totals->dividendsGross),
                'interestGross' => $amount($totals->interestGross),
                'fees' => $amount($totals->fees),
                'taxes' => $amount($totals->taxes),
                'cashInLieu' => $amount($totals->cashInLieu),
                'roundingResiduals' => $amount($totals->roundingResiduals),
            ],
            'movements' => array_map(static fn (CashMovement $movement): array => [
                'operationId' => $movement->operationId,
                'tradeDate' => $movement->tradeDate->format('Y-m-d'),
                'sequence' => $movement->sequence,
                'type' => $movement->type->value,
                'cashAmount' => $amount($movement->cashAmount),
                'runningBalance' => $amount($movement->runningBalance),
            ], $view->ledger->movements),
            'warnings' => array_map(static fn (CashWarning $warning): array => [
                'code' => $warning->code->value,
                'count' => $warning->count,
                'sourceIds' => $warning->sourceIds,
                'firstDate' => $warning->firstDate?->format('Y-m-d'),
                'amount' => null === $warning->amount ? null : $amount($warning->amount),
            ], $view->warnings),
            'costPolicy' => [
                'performanceBasis' => PortfolioCostPolicy::PERFORMANCE_BASIS,
                'budgetTreatment' => PortfolioCostPolicy::BUDGET_TREATMENT,
            ],
        ];
    }
}
