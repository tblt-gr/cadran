<?php

declare(strict_types=1);

namespace App\Module\Accounts\UI\Http;

use App\Module\Accounts\Application\AccountPage;
use App\Module\Accounts\Application\AccountView;

/**
 * The wire shape of an account, kept apart from the controller that serves it.
 *
 * A view is an application-layer object free to gain fields the contract does
 * not publish; this class is the single place that decides what actually
 * leaves the API, so a new view field never reaches a client by accident.
 */
final readonly class AccountRepresentation
{
    /** @return array<string, mixed> */
    public static function one(AccountView $account): array
    {
        return [
            'id' => $account->id,
            'label' => $account->label,
            'assetCode' => $account->assetCode,
            'kind' => $account->kind,
            'productCode' => $account->productCode,
            'productModelId' => $account->productModelId,
            'institution' => $account->institution,
            'maskedIdentifier' => $account->maskedIdentifier,
            'valuationMode' => $account->valuationMode,
            'liquidityLevel' => $account->liquidityLevel,
            'includeInNetWorth' => $account->includeInNetWorth,
            'includeInEmergencyFund' => $account->includeInEmergencyFund,
            'openedOn' => $account->openedOn,
            'closedOn' => $account->closedOn,
            'status' => $account->status,
            'netWorthSign' => $account->netWorthSign,
            'used' => $account->used,
            'editable' => $account->editable,
            'kindEditable' => $account->kindEditable,
            'kindEditReason' => $account->kindEditReason,
            'version' => $account->version,
            'archivedAt' => $account->archivedAt,
        ];
    }

    /** @return array<string, mixed> */
    public static function page(AccountPage $page): array
    {
        return [
            'items' => array_map(self::one(...), $page->items),
            'page' => $page->page,
            'perPage' => $page->perPage,
            'total' => $page->total,
        ];
    }
}
