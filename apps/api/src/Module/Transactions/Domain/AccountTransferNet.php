<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain;

use App\Module\Foundation\Domain\DecimalValue;

/**
 * One account's exact net booked transfer movement for a calendar month.
 *
 * A published reason replaces the figure; it never accompanies one, and the
 * figure is never defaulted to zero when a pair cannot be resolved.
 */
final readonly class AccountTransferNet
{
    private function __construct(
        public ?DecimalValue $value,
        public ?AccountTransferNetReason $reason,
    ) {
        if ((null === $value) === (null === $reason)) {
            throw new \InvalidArgumentException('An account transfer net carries either a value or a reason.');
        }
    }

    public static function value(DecimalValue $value): self
    {
        return new self($value, null);
    }

    public static function missing(AccountTransferNetReason $reason): self
    {
        return new self(null, $reason);
    }
}
