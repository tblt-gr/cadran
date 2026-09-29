<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\EmergencyFund;

final readonly class EmergencyFundPolicyInput
{
    /** @param list<string> $essentialCategoryIds */
    public function __construct(
        public string $essentialMode,
        public array $essentialCategoryIds,
        public string $referenceMode,
        public ?int $trailingMonths,
        public string $targetMonths,
        public int $expectedVersion,
    ) {
    }
}
