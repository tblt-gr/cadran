<?php

declare(strict_types=1);

namespace App\Module\Reporting\UI\Http;

use App\Module\Accounts\Application\AccountView;

final readonly class MonthlySelectedAccountRepresentation
{
    /** @return array<string, mixed> */
    public static function of(AccountView $account): array
    {
        $valuation = $account->valuation ?? throw new \LogicException('A selected account carries its dated valuation.');

        return [
            'accountId' => $account->id,
            'label' => $account->label,
            'assetCode' => $account->assetCode,
            'status' => $account->status,
            'closedOn' => $account->closedOn,
            'archivedAt' => $account->archivedAt,
            'value' => $valuation->amount,
            'displayValue' => $valuation->belowDisplayStep ? null : $valuation->displayAmount,
            'belowDisplayStep' => $valuation->belowDisplayStep,
            'requestedOn' => $valuation->requestedOn,
            'valuedOn' => $valuation->asOf,
            'quality' => $valuation->quality,
            'source' => $valuation->source,
            'ageDays' => $valuation->ageDays,
            'basis' => $valuation->basis,
            'reason' => $valuation->reason ?? (null === $valuation->amount ? ($account->openedOn > $valuation->requestedOn ? 'NOT_YET_OPENED' : 'MISSING_VALUATION') : null),
        ];
    }
}
