<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

use App\Module\Foundation\Domain\AssetAmount;

/**
 * The comparison of one measured figure to one ceiling. It never decides
 * whether a write is allowed: exceeding stays a warning so a Livret Bleu,
 * credited interest or an imported history can sit above the published
 * amount without being rejected.
 */
final readonly class CeilingComparison
{
    public function __construct(
        public CeilingCheckStatus $status,
        public ?AssetAmount $measured,
        public ?AssetAmount $excess,
        public ?CeilingUnsettledReason $unsettledReason,
    ) {
    }

    public function isWarning(): bool
    {
        return CeilingCheckStatus::EXCEEDED === $this->status;
    }

    public function isRefusal(): bool
    {
        return false;
    }
}
