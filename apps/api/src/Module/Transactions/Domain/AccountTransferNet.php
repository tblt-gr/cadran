<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;

/**
 * One account's exact net booked transfer movement for a calendar month.
 *
 * A published reason replaces the figure; it never accompanies one, and the
 * figure is never defaulted to zero when a pair cannot be resolved. The asset of the booked
 * movements travels with the figure, so a caller never reads it in another currency; it is null
 * only when the month holds no booked movement of the account.
 */
final readonly class AccountTransferNet
{
    private function __construct(
        public ?DecimalValue $value,
        public ?AccountTransferNetReason $reason,
        public ?AssetCode $asset = null,
    ) {
        if ((null === $value) === (null === $reason)) {
            throw new \InvalidArgumentException('An account transfer net carries either a value or a reason.');
        }
    }

    public static function value(DecimalValue $value, ?AssetCode $asset = null): self
    {
        return new self($value, null, $asset);
    }

    public static function missing(AccountTransferNetReason $reason): self
    {
        return new self(null, $reason);
    }
}
