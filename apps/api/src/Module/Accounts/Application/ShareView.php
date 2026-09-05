<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\NetWorthShare;
use App\Module\Accounts\Domain\NetWorthShareReason;

/**
 * The published weight of one account or group. Both figures come from the
 * backend; the interface never multiplies a ratio itself.
 */
final readonly class ShareView
{
    public function __construct(
        public ?string $ratio,
        public ?string $percent,
        public ?string $reason,
    ) {
    }

    public static function fromShare(NetWorthShare $share): self
    {
        return new self(
            $share->ratio?->toString(),
            $share->percent?->toString(),
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
