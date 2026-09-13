<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application\Recurrence;

use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Transactions\Domain\Recurrence\TransactionRecurrence;

final readonly class RecurrenceView
{
    /** @return array<string, mixed> */
    public static function from(TransactionRecurrence $recurrence): array
    {
        return [
            'id' => $recurrence->id,
            'accountId' => $recurrence->accountId,
            'label' => $recurrence->label,
            'counterparty' => $recurrence->counterparty,
            'expectedAmount' => self::amount($recurrence->expectedAmount),
            'amountTolerance' => self::amount($recurrence->amountTolerance),
            'intervalKind' => $recurrence->intervalKind->value,
            'dayOfPeriod' => $recurrence->dayOfPeriod,
            'nextExpectedOn' => $recurrence->nextExpectedOn->format('Y-m-d'),
            'confirmedAt' => $recurrence->confirmedAt->format(DATE_ATOM),
            'version' => $recurrence->version,
            'createdAt' => $recurrence->createdAt->format(DATE_ATOM),
            'updatedAt' => $recurrence->updatedAt->format(DATE_ATOM),
            'archivedAt' => $recurrence->archivedAt?->format(DATE_ATOM),
        ];
    }

    /** @return array{value: string, assetCode: string} */
    public static function amount(AssetAmount $amount): array
    {
        return ['value' => $amount->value->toString(), 'assetCode' => $amount->asset->toString()];
    }
}
