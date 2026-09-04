<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

final readonly class UpdateAccountInput
{
    public function __construct(
        public string $label,
        public string $kind,
        public ?string $productCode,
        public ?string $productModelId,
        public ?string $institution,
        public ?string $maskedIdentifier,
        public string $valuationMode,
        public string $liquidityLevel,
        public bool $includeInNetWorth,
        public bool $includeInEmergencyFund,
        public string $openedOn,
        public ?string $closedOn,
        public int $version,
    ) {
    }
}
