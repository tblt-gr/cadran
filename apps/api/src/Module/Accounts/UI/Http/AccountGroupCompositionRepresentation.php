<?php

declare(strict_types=1);

namespace App\Module\Accounts\UI\Http;

use App\Module\Accounts\Application\GroupCompositionEntryView;
use App\Module\Accounts\Application\GroupCompositionView;
use App\Module\Accounts\Application\NetWorthAmountView;

/** The wire shape of the composition of one group. */
final readonly class AccountGroupCompositionRepresentation
{
    /** @return array<string, mixed> */
    public static function composition(GroupCompositionView $view): array
    {
        return [
            'groupId' => $view->groupId,
            'label' => $view->label,
            'asOf' => $view->asOf,
            'total' => self::amount($view->total),
            'totalReason' => $view->totalReason,
            'chartReason' => $view->chartReason,
            'bySubgroup' => array_map(self::subgroup(...), $view->bySubgroup),
            'byAccount' => array_map(self::account(...), $view->byAccount),
        ];
    }

    /** @return array<string, mixed> */
    private static function subgroup(GroupCompositionEntryView $entry): array
    {
        return [
            'kind' => $entry->kind,
            'id' => $entry->id,
            'label' => $entry->label,
            'color' => $entry->color,
            'value' => self::amount($entry->value),
            'share' => AccountGroupRepresentation::share($entry->share),
        ];
    }

    /** @return array<string, mixed> */
    private static function account(GroupCompositionEntryView $entry): array
    {
        return [
            'id' => $entry->id,
            'label' => $entry->label,
            'institution' => $entry->institution,
            'primaryGroupId' => $entry->primaryGroupId,
            'color' => $entry->color,
            'value' => self::amount($entry->value),
            'share' => AccountGroupRepresentation::share($entry->share),
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
