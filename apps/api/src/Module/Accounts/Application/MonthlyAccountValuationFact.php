<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Foundation\Domain\AssetAmount;

final readonly class MonthlyAccountValuationFact
{
    public function __construct(
        public ?AssetAmount $amount,
        public string $quality,
        public ?int $ageDays,
        public ?string $valuedOn,
    ) {
    }
}
