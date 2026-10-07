<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

final readonly class OperationPreview
{
    /**
     * @param array{value: string, assetCode: string}      $cashAmount
     * @param array{value: string, assetCode: string}|null $roundingResidual
     * @param array{value: string, assetCode: string}|null $expectedGrossAmount
     * @param list<array<string, mixed>>                   $legs
     * @param array{value: string, assetCode: string}|null $netPaidAmount
     * @param array{value: string, assetCode: string}|null $expectedIncomingAmount
     * @param array{value: string, assetCode: string}|null $difference
     * @param array{value: string, assetCode: string}|null $applicableSwitchFeeFixed
     * @param array{value: string, assetCode: string}|null $netCreditedAmount
     */
    public function __construct(public array $cashAmount, public ?array $roundingResidual, public ?array $expectedGrossAmount, public array $legs = [], public ?array $netPaidAmount = null, public ?array $expectedIncomingAmount = null, public ?array $difference = null, public ?string $applicableSwitchFeeRate = null, public ?array $applicableSwitchFeeFixed = null, public ?array $netCreditedAmount = null)
    {
    }
}
