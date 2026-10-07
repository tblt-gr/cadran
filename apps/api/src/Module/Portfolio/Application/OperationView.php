<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

/**
 * An operation as the API shows it. Amounts are `{value, assetCode}` pairs of
 * canonical decimal strings; quantities, prices and ratios are plain strings.
 */
final readonly class OperationView
{
    /**
     * @param array{id: string, name: string, isin: string|null, ticker: string|null, status: string}|null $instrument
     * @param array{id: string, name: string, isin: string|null, ticker: string|null, status: string}|null $counterInstrument
     * @param array{value: string, assetCode: string}|null                                                 $grossAmount
     * @param array{value: string, assetCode: string}|null                                                 $fees
     * @param array{value: string, assetCode: string}|null                                                 $taxes
     * @param array{value: string, assetCode: string}                                                      $cashAmount
     * @param array{value: string, assetCode: string}|null                                                 $costBasis
     * @param array{numerator: string, denominator: string}|null                                           $ratio
     * @param array{value: string, assetCode: string}|null                                                 $roundingResidual
     * @param list<array<string, mixed>>                                                                   $legs
     * @param array{value: string, assetCode: string}|null                                                 $netPaidAmount
     * @param array{value: string, assetCode: string}|null                                                 $withheldIncomeTax
     * @param array{value: string, assetCode: string}|null                                                 $withheldSocialContributions
     * @param array{value: string, assetCode: string}|null                                                 $applicableSwitchFeeFixed
     */
    public function __construct(
        public string $id,
        public string $accountId,
        public string $type,
        public string $tradeDate,
        public ?string $settlementDate,
        public int $sequence,
        public int $activeDayOperationCount,
        public ?array $instrument,
        public ?array $counterInstrument,
        public ?string $quantity,
        public ?string $unitPrice,
        public ?array $grossAmount,
        public ?array $fees,
        public ?array $taxes,
        public array $cashAmount,
        public ?array $costBasis,
        public ?string $counterQuantity,
        public ?array $ratio,
        public ?string $costAllocationRatio,
        public ?array $roundingResidual,
        public ?string $reference,
        public ?string $note,
        public ?string $linkedTransactionId,
        public string $state,
        public ?string $voidedAt,
        public ?string $voidReason,
        public ?string $voidKind,
        public ?string $correctsOperationId,
        public ?string $correctedByOperationId,
        public int $version,
        public array $legs = [],
        public ?array $withheldIncomeTax = null,
        public ?array $withheldSocialContributions = null,
        public ?array $netPaidAmount = null,
        public ?string $applicableSwitchFeeRate = null,
        public ?array $applicableSwitchFeeFixed = null,
        public ?string $interestBasis = null,
        public ?int $rateYear = null,
        public ?string $feeNature = null,
    ) {
    }
}
