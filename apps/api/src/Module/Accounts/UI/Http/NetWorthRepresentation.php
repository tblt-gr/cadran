<?php

declare(strict_types=1);

namespace App\Module\Accounts\UI\Http;

use App\Module\Accounts\Application\NetWorthAllocationView;
use App\Module\Accounts\Application\NetWorthAmountView;
use App\Module\Accounts\Application\NetWorthContributionView;
use App\Module\Accounts\Application\NetWorthDeltaView;
use App\Module\Accounts\Application\NetWorthHistoryView;
use App\Module\Accounts\Application\NetWorthPointView;
use App\Module\Accounts\Application\NetWorthView;

/**
 * The wire shape of an explainable net worth.
 *
 * Every figure is a canonical decimal string with its asset, and every
 * absent figure is null beside the reason it is absent. Nothing here is
 * pre-formatted for a screen and nothing is rounded away from its source.
 */
final readonly class NetWorthRepresentation
{
    /** @return array<string, mixed> */
    public static function of(NetWorthView $netWorth): array
    {
        return [
            'asOf' => $netWorth->asOf,
            'total' => self::amount($netWorth->total),
            'reason' => $netWorth->reason,
            'quality' => $netWorth->quality,
            'stalestAgeDays' => $netWorth->stalestAgeDays,
            'eligibleAccountCount' => $netWorth->eligibleAccountCount,
            'valuedAccountCount' => $netWorth->valuedAccountCount,
            'missingValuationCount' => $netWorth->missingValuationCount,
            'staleValuationCount' => $netWorth->staleValuationCount,
            'delta' => self::delta($netWorth->delta),
            'contributions' => array_map(self::contribution(...), $netWorth->contributions),
            'allocation' => array_map(self::allocation(...), $netWorth->allocation),
        ];
    }

    /** @return array<string, mixed> */
    public static function history(NetWorthHistoryView $history): array
    {
        return [
            'asOf' => $history->asOf,
            'granularity' => $history->granularity,
            'points' => array_map(self::point(...), $history->points),
        ];
    }

    /** @return array<string, mixed> */
    private static function point(NetWorthPointView $point): array
    {
        return [
            'on' => $point->on,
            'total' => self::amount($point->total),
            'reason' => $point->reason,
            'quality' => $point->quality,
        ];
    }

    /** @return array<string, mixed> */
    private static function delta(NetWorthDeltaView $delta): array
    {
        return [
            'comparedOn' => $delta->comparedOn,
            'previousTotal' => self::amount($delta->previousTotal),
            'amount' => self::amount($delta->amount),
            'amountReason' => $delta->amountReason,
            'rate' => $delta->rate,
            'ratePercent' => $delta->ratePercent,
            'ratePercentDisplay' => $delta->ratePercentDisplay,
            'rateReason' => $delta->rateReason,
        ];
    }

    /** @return array<string, mixed> */
    private static function contribution(NetWorthContributionView $contribution): array
    {
        return [
            'accountId' => $contribution->accountId,
            'label' => $contribution->label,
            'kind' => $contribution->kind,
            'netWorthSign' => $contribution->netWorthSign,
            'primaryGroupId' => $contribution->primaryGroupId,
            'primaryGroupLabel' => $contribution->primaryGroupLabel,
            'eligible' => $contribution->eligible,
            'amount' => self::amount($contribution->amount),
            'signedAmount' => self::amount($contribution->signedAmount),
            'quality' => $contribution->quality,
            'ageDays' => $contribution->ageDays,
            'valuedOn' => $contribution->valuedOn,
            'share' => AccountGroupRepresentation::share($contribution->share),
        ];
    }

    /** @return array<string, mixed> */
    private static function allocation(NetWorthAllocationView $allocation): array
    {
        return [
            'groupId' => $allocation->groupId,
            'label' => $allocation->label,
            'parentId' => $allocation->parentId,
            'depth' => $allocation->depth,
            'value' => self::amount($allocation->value),
            'share' => AccountGroupRepresentation::share($allocation->share),
        ];
    }

    /** @return array<string, mixed>|null */
    private static function amount(?NetWorthAmountView $amount): ?array
    {
        if (null === $amount) {
            return null;
        }

        return [
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
