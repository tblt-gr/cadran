<?php

declare(strict_types=1);

namespace App\Module\Accounts\UI\Http;

use App\Module\Accounts\Application\AccountValuationHistoryPoint;
use App\Module\Accounts\Application\AccountValuationHistoryView;

final readonly class AccountValuationHistoryRepresentation
{
    /** @return array<string, mixed> */
    public static function of(AccountValuationHistoryView $history): array
    {
        return [
            'accountId' => $history->accountId,
            'from' => $history->from,
            'to' => $history->to,
            'hasHistory' => $history->hasHistory,
            'truncated' => $history->truncated,
            'points' => array_map(self::point(...), $history->points),
        ];
    }

    /** @return array<string, mixed> */
    private static function point(AccountValuationHistoryPoint $point): array
    {
        return [
            'date' => $point->date,
            'amount' => $point->amount,
            'assetCode' => $point->assetCode,
            'quality' => $point->quality,
            'source' => $point->source,
            'reason' => $point->reason,
            'asOf' => $point->asOf,
            'ageDays' => $point->ageDays,
            'basis' => $point->basis,
            'derivation' => null === $point->derivation ? null : get_object_vars($point->derivation),
        ];
    }
}
