<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

/** The create body of an operation, checked for JSON shape only; every business rule is applied by the resolver. */
final readonly class OperationInput
{
    /** @param list<OperationLegInput> $legs */
    public function __construct(
        public string $type,
        public string $tradeDate,
        public ?string $settlementDate = null,
        public ?string $instrumentId = null,
        public ?string $quantity = null,
        public ?string $unitPrice = null,
        public ?AmountInput $grossAmount = null,
        public ?AmountInput $fees = null,
        public ?AmountInput $taxes = null,
        public ?AmountInput $costBasis = null,
        public ?string $counterInstrumentId = null,
        public ?string $counterQuantity = null,
        public ?string $ratioNumerator = null,
        public ?string $ratioDenominator = null,
        public ?string $costAllocationRatio = null,
        public ?string $reference = null,
        public ?string $note = null,
        public ?string $linkedTransactionId = null,
        public array $legs = [],
        public ?AmountInput $withheldIncomeTax = null,
        public ?AmountInput $withheldSocialContributions = null,
    ) {
    }
}
