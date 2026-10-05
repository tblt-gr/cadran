<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application\LifeInsurance;

use App\Module\Portfolio\Application\AmountInput;

final readonly class FeeTermsInput
{
    public function __construct(
        public string $validFrom,
        public ?string $entryFeeRate,
        public ?string $euroFundManagementFeeRate,
        public ?string $unitLinkedManagementFeeRate,
        public ?string $switchFeeRate,
        public ?AmountInput $switchFeeFixed,
        public ?string $sourceNote,
    ) {
    }
}
