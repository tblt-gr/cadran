<?php

declare(strict_types=1);

namespace App\Module\Transactions\UI\Http;

use App\Module\Transactions\Application\TransferView;

final readonly class TransferRepresentation
{
    /** @return array<string, mixed> */
    public static function one(TransferView $transfer): array
    {
        return [
            'id' => $transfer->id,
            'source' => TransactionRepresentation::one($transfer->source),
            'target' => TransactionRepresentation::one($transfer->target),
            'fee' => null === $transfer->fee ? null : TransactionRepresentation::one($transfer->fee),
            'exchangeRate' => $transfer->exchangeRate,
            'version' => $transfer->version,
            'createdAt' => $transfer->createdAt,
            'updatedAt' => $transfer->updatedAt,
            'voidedAt' => $transfer->voidedAt,
        ];
    }
}
