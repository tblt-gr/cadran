<?php

declare(strict_types=1);

namespace App\Module\Transactions\UI\Http;

use App\Module\Transactions\Application\TransactionPage;
use App\Module\Transactions\Application\TransactionView;

final readonly class TransactionRepresentation
{
    /** @return array<string, mixed> */
    public static function one(TransactionView $transaction): array
    {
        return [
            'id' => $transaction->id,
            'accountId' => $transaction->accountId,
            'amount' => $transaction->amount,
            'originalAmount' => $transaction->originalAmount,
            'exchangeRate' => $transaction->exchangeRate,
            'nature' => $transaction->nature,
            'state' => $transaction->state,
            'source' => $transaction->source,
            'bookedOn' => $transaction->bookedOn,
            'valueOn' => $transaction->valueOn,
            'authorizedOn' => $transaction->authorizedOn,
            'rawLabel' => $transaction->rawLabel,
            'counterparty' => $transaction->counterparty,
            'note' => $transaction->note,
            'paymentMethod' => $transaction->paymentMethod,
            'mcc' => $transaction->mcc,
            'maskedCard' => $transaction->maskedCard,
            'bankReference' => $transaction->bankReference,
            'splits' => $transaction->splits,
            'version' => $transaction->version,
            'createdAt' => $transaction->createdAt,
            'updatedAt' => $transaction->updatedAt,
            'voidedAt' => $transaction->voidedAt,
        ];
    }

    /** @return array<string, mixed> */
    public static function page(TransactionPage $page): array
    {
        return [
            'items' => array_map(self::one(...), $page->items),
            'nextCursor' => $page->nextCursor,
        ];
    }
}
