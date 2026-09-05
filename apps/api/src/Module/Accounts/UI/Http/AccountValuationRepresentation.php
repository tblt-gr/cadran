<?php

declare(strict_types=1);

namespace App\Module\Accounts\UI\Http;

use App\Module\Accounts\Application\ValuationView;

/**
 * The wire shape of the latest valid snapshot on a requested date.
 *
 * Source and display stay distinct. A missing valuation is null on both
 * sides, never a silent zero.
 */
final readonly class AccountValuationRepresentation
{
    /** @return array<string, mixed> */
    public static function of(ValuationView $valuation): array
    {
        return [
            'accountId' => $valuation->accountId,
            'requestedOn' => $valuation->requestedOn,
            'asOf' => $valuation->asOf,
            'amount' => null === $valuation->amount || null === $valuation->assetCode ? null : [
                'value' => $valuation->amount,
                'assetCode' => $valuation->assetCode,
            ],
            'display' => self::display($valuation),
            'belowDisplayStep' => $valuation->belowDisplayStep,
            'source' => $valuation->source,
            'ageDays' => $valuation->ageDays,
            'quality' => $valuation->quality,
            'reconciliationStatus' => $valuation->reconciliationStatus,
            'snapshotId' => $valuation->snapshotId,
            'version' => $valuation->version,
        ];
    }

    /** @return array<string, string>|null */
    private static function display(ValuationView $valuation): ?array
    {
        if ($valuation->belowDisplayStep || null === $valuation->displayAmount || null === $valuation->assetCode) {
            return null;
        }

        return [
            'value' => $valuation->displayAmount,
            'assetCode' => $valuation->assetCode,
        ];
    }
}
