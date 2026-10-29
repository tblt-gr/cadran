<?php

declare(strict_types=1);

namespace App\Module\Budget\Domain\EmergencyFund;

/**
 * The amount a fund must hold when the policy sets it directly instead of through the essential
 * average: a fixed amount, or a share of the annual income. A null amount carries the reason it
 * could not be worked out; a null asset code means the amount is in the reports' own asset.
 */
final readonly class FixedRequirement
{
    public function __construct(
        public ?string $amount,
        public ?string $assetCode,
        public ?string $reason,
    ) {
    }
}
