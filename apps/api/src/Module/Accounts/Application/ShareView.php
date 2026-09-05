<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\NetWorthShare;
use App\Module\Accounts\Domain\NetWorthShareReason;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Foundation\Domain\RoundingMode;

/**
 * The published weight of one account or group. Both figures come from the
 * backend; the interface never multiplies a ratio itself.
 */
final readonly class ShareView
{
    /**
     * Presentation scale of an exclusive weight. The exact percent stays
     * available beside it: a share is almost never a terminating decimal, so
     * a bar that printed all 24 places would be unreadable, and rounding it
     * in the interface would move a rounding decision out of the backend.
     */
    public const int PERCENT_DISPLAY_SCALE = 2;

    public function __construct(
        public ?string $ratio,
        public ?string $percent,
        public ?string $percentDisplay,
        public ?string $reason,
    ) {
    }

    public static function fromShare(NetWorthShare $share): self
    {
        return new self(
            $share->ratio?->toString(),
            $share->percent?->toString(),
            null === $share->percent
                ? null
                : ExactDecimal::round($share->percent, self::PERCENT_DISPLAY_SCALE, RoundingMode::HALF_UP)->toString(),
            $share->reason?->value,
        );
    }

    public static function missingValuation(): self
    {
        return self::fromShare(NetWorthShare::none(NetWorthShareReason::MISSING_VALUATION));
    }

    public static function notApplicable(): self
    {
        return self::fromShare(NetWorthShare::none(null));
    }
}
