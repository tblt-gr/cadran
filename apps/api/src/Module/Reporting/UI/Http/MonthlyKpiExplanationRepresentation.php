<?php

declare(strict_types=1);

namespace App\Module\Reporting\UI\Http;

use App\Module\Reporting\Application\MonthlyKpiExplanationView;

final class MonthlyKpiExplanationRepresentation
{
    /** @return array<string, mixed> */
    public static function of(MonthlyKpiExplanationView $view): array
    {
        return [
            'kpi' => $view->kpi,
            'value' => $view->value,
            'assetCode' => $view->assetCode,
            'reason' => $view->reason,
            'reasonExplanation' => $view->reasonExplanation,
            'formula' => $view->formula,
            'scope' => $view->scope,
            'period' => ['start' => $view->periodStart, 'end' => $view->periodEnd],
            'sourceTransactionIds' => $view->sourceTransactionIds,
            'sourceTransactions' => array_map(static fn ($transaction): array => [
                'id' => $transaction->id,
                'bookedOn' => $transaction->bookedOn,
                'label' => $transaction->label,
                'amount' => ['value' => $transaction->amount, 'assetCode' => $transaction->assetCode],
                'state' => $transaction->state,
            ], $view->sourceTransactions),
            'sourceTransferIds' => $view->sourceTransferIds,
            'sourceAccountIds' => $view->sourceAccountIds,
            'freshness' => $view->freshness,
            'quality' => $view->quality,
            'pendingCount' => $view->pendingCount,
            'metricPolicy' => MetricPolicyRepresentation::reference($view->metricPolicy),
        ];
    }
}
