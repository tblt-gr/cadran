<?php

declare(strict_types=1);

namespace App\Module\Accounts\UI\Http;

use App\Module\Accounts\Application\AccountBalanceSnapshotPage;
use App\Module\Accounts\Domain\AccountBalanceSnapshot;

final readonly class AccountBalanceSnapshotRepresentation
{
    /** @return array<string, mixed> */
    public static function one(AccountBalanceSnapshot $snapshot): array
    {
        return [
            'id' => $snapshot->id,
            'accountId' => $snapshot->accountId,
            'asOf' => $snapshot->asOf->format('Y-m-d'),
            'amount' => [
                'value' => $snapshot->amount->value->toString(),
                'assetCode' => $snapshot->amount->asset->toString(),
            ],
            'source' => $snapshot->source->value,
            'reconciliationStatus' => $snapshot->reconciliationStatus->value,
            'comment' => $snapshot->comment,
            'active' => $snapshot->isActive(),
            'version' => $snapshot->version,
            'recordedAt' => $snapshot->recordedAt->format(\DATE_ATOM),
            'supersededAt' => $snapshot->supersededAt?->format(\DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public static function page(AccountBalanceSnapshotPage $page): array
    {
        return [
            'items' => array_map(self::one(...), $page->items),
            'page' => $page->page,
            'perPage' => $page->perPage,
            'total' => $page->total,
        ];
    }
}
