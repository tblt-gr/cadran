<?php

declare(strict_types=1);

namespace App\Module\Budget\Domain\EmergencyFund;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;

/**
 * One eligible account at the as-of date, valued by the net-worth rule: a signed value, or null
 * when it has no valuation on or before that date.
 */
final readonly class LiquidAccount
{
    public function __construct(
        public string $id,
        public string $label,
        public ?DecimalValue $value,
        public ?AssetCode $asset,
        public ?\DateTimeImmutable $valuedOn,
        public ?int $ageDays,
    ) {
    }

    public function isStale(): bool
    {
        return null !== $this->ageDays && $this->ageDays > EmergencyFundPolicy::STALE_VALUATION_DAYS;
    }
}
