<?php

declare(strict_types=1);

namespace App\Module\Portfolio\UI\Http;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Portfolio\Application\LifeInsurance\EuroFundState;
use App\Module\Portfolio\Application\LifeInsurance\LifeInsuranceOverview;
use App\Module\Portfolio\Domain\LifeInsurance\EuroFundRate;
use App\Module\Portfolio\Domain\LifeInsurance\FeeTerms;
use App\Module\Portfolio\Domain\LifeInsurance\LifeInsuranceContract;

/** The wire shape of the life-insurance resources. The contract number leaves only as its masked suffix. */
final class LifeInsuranceRepresentation
{
    /** @return array<string, mixed> */
    public static function overview(LifeInsuranceOverview $overview): array
    {
        return [
            'contract' => null === $overview->contract ? null : self::contract($overview->contract),
            'feeTerms' => array_map(static fn (FeeTerms $terms): array => self::feeTerms($terms, $overview->assetCode), $overview->feeTerms),
            'euroFundRates' => array_map(self::rate(...), $overview->euroFundRates),
            'euroFunds' => array_map(self::euroFund(...), $overview->euroFunds),
        ];
    }

    /** @return array<string, mixed> */
    public static function contract(LifeInsuranceContract $contract): array
    {
        return [
            'id' => $contract->id,
            'accountId' => $contract->accountId,
            'insurer' => $contract->insurer,
            'distributor' => $contract->distributor,
            'commercialName' => $contract->commercialName,
            'contractNumberMasked' => $contract->numberSuffix?->masked(),
            'subscribedOn' => $contract->subscribedOn->format('Y-m-d'),
            'taxStartOn' => $contract->taxStartOn->format('Y-m-d'),
            'managementMode' => $contract->managementMode->value,
            'status' => $contract->status->value,
            'closedOn' => $contract->closedOn?->format('Y-m-d'),
            'version' => $contract->version,
        ];
    }

    /** @return array<string, mixed> */
    public static function feeTerms(FeeTerms $terms, string $assetCode): array
    {
        return [
            'id' => $terms->id,
            'validFrom' => $terms->validFrom->format('Y-m-d'),
            'entryFeeRate' => $terms->entryFeeRate?->toString(),
            'euroFundManagementFeeRate' => $terms->euroFundManagementFeeRate?->toString(),
            'unitLinkedManagementFeeRate' => $terms->unitLinkedManagementFeeRate?->toString(),
            'switchFeeRate' => $terms->switchFeeRate?->toString(),
            'switchFeeFixed' => $terms->switchFeeFixed instanceof DecimalValue ? ['value' => $terms->switchFeeFixed->toString(), 'assetCode' => $assetCode] : null,
            'sourceNote' => $terms->sourceNote,
        ];
    }

    /** @return array<string, mixed> */
    public static function rate(EuroFundRate $rate): array
    {
        return [
            'id' => $rate->id,
            'instrumentId' => $rate->instrumentId,
            'year' => $rate->year,
            'servedRate' => $rate->servedRate->toString(),
            'bonusRate' => $rate->bonusRate?->toString(),
            'basis' => $rate->basis->value,
            'sourceNote' => $rate->sourceNote,
        ];
    }

    /** @return array<string, mixed> */
    private static function euroFund(EuroFundState $state): array
    {
        return [
            'instrument' => InstrumentRepresentation::one($state->instrument),
            'latestRateYear' => $state->latestRateYear,
            'missingLastYear' => $state->missingLastYear,
        ];
    }
}
