<?php

declare(strict_types=1);

namespace App\Module\Accounts\UI\Http;

use App\Module\Accounts\Application\AccountOverviewCompositionAccountView;
use App\Module\Accounts\Application\AccountOverviewCompositionView;
use App\Module\Accounts\Application\NetWorthAmountView;

/** The wire shape of the composition of the whole net worth by account. */
final readonly class AccountOverviewCompositionRepresentation
{
    /** @return array<string, mixed> */
    public static function composition(AccountOverviewCompositionView $view): array
    {
        return [
            'asOf' => $view->asOf,
            'total' => self::amount($view->total),
            'totalReason' => $view->totalReason,
            'chartReason' => $view->chartReason,
            'accounts' => array_map(self::account(...), $view->accounts),
        ];
    }

    /** @return array<string, mixed> */
    private static function account(AccountOverviewCompositionAccountView $account): array
    {
        return [
            'id' => $account->id,
            'label' => $account->label,
            'institution' => $account->institution,
            'primaryGroupId' => $account->primaryGroupId,
            'color' => $account->color,
            'value' => self::amount($account->value),
            'share' => AccountGroupRepresentation::share($account->share),
        ];
    }

    /** @return array<string, mixed>|null */
    private static function amount(?NetWorthAmountView $amount): ?array
    {
        return null === $amount ? null : [
            'value' => $amount->amount,
            'assetCode' => $amount->asset,
            'display' => null === $amount->display ? null : [
                'value' => $amount->display,
                'assetCode' => $amount->asset,
            ],
            'belowDisplayStep' => $amount->belowDisplayStep,
        ];
    }
}
