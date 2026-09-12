<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Catalog\Domain\BusinessDay;
use App\Module\Transactions\Domain\Transaction;
use App\Module\Transactions\Domain\TransactionState;

/**
 * Parses the fields shared by a transfer's creation and edition. Both legs
 * and the optional fee always carry the same state, booked date, value date
 * and label, so a single draft is enough for the whole transfer.
 */
final readonly class TransferInputParser
{
    public function parse(string $state, string $bookedOn, ?string $valueOn, string $label): TransferDraft
    {
        return new TransferDraft(
            state: self::parseState($state),
            bookedOn: self::parseDay($bookedOn),
            valueOn: null === $valueOn ? null : self::parseDay($valueOn),
            label: self::parseLabel($label),
        );
    }

    private static function parseState(string $state): TransactionState
    {
        try {
            $parsed = TransactionState::from($state);
        } catch (\ValueError $exception) {
            throw new InvalidTransferInput('A transfer state is not supported.', previous: $exception);
        }
        if (!in_array($parsed, [TransactionState::PENDING, TransactionState::BOOKED], true)) {
            throw new InvalidTransferInput('A transfer must be pending or booked.');
        }

        return $parsed;
    }

    private static function parseDay(string $day): \DateTimeImmutable
    {
        try {
            return BusinessDay::fromIsoDate($day)->date;
        } catch (\Throwable $exception) {
            throw new InvalidTransferInput('A transfer date is not a valid calendar day.', previous: $exception);
        }
    }

    private static function parseLabel(string $label): string
    {
        if ($label !== trim($label) || '' === $label || mb_strlen($label) > Transaction::MAX_RAW_LABEL_LENGTH) {
            throw new InvalidTransferInput('A transfer label must contain between 1 and '.Transaction::MAX_RAW_LABEL_LENGTH.' characters.');
        }

        return $label;
    }
}
