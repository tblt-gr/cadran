<?php

declare(strict_types=1);

namespace App\Module\Portfolio\UI\Http;

use App\Module\Foundation\UI\Http\JsonPayload;
use App\Module\Foundation\UI\Http\MalformedPayload;
use App\Module\Portfolio\Application\LifeInsurance\EuroFundRateInput;
use App\Module\Portfolio\Application\LifeInsurance\FeeTermsInput;
use App\Module\Portfolio\Application\LifeInsurance\LifeInsuranceContractInput;

/**
 * Reads the JSON shape of the life-insurance bodies: declared fields only, strings where a string is expected,
 * and a number where a decimal string belongs is refused, never coerced.
 */
final class LifeInsurancePayload
{
    /**
     * @param array<mixed> $body
     *
     * @throws MalformedPayload
     */
    public static function contract(array $body): LifeInsuranceContractInput
    {
        $payload = JsonPayload::of(
            $body,
            ['insurer', 'subscribedOn', 'managementMode'],
            ['distributor', 'commercialName', 'contractNumber', 'taxStartOn', 'version'],
        );

        return new LifeInsuranceContractInput(
            insurer: $payload->string('insurer'),
            distributor: $payload->nullableString('distributor'),
            commercialName: $payload->nullableString('commercialName'),
            contractNumberProvided: array_key_exists('contractNumber', $body),
            contractNumber: $payload->nullableString('contractNumber'),
            subscribedOn: $payload->string('subscribedOn'),
            taxStartOn: $payload->nullableString('taxStartOn'),
            managementMode: $payload->string('managementMode'),
            version: $payload->nullableInteger('version'),
        );
    }

    /**
     * @param array<mixed> $body
     *
     * @throws MalformedPayload
     */
    public static function feeTerms(array $body): FeeTermsInput
    {
        $payload = JsonPayload::of(
            $body,
            ['validFrom'],
            ['entryFeeRate', 'euroFundManagementFeeRate', 'unitLinkedManagementFeeRate', 'switchFeeRate', 'switchFeeFixed', 'sourceNote'],
        );

        return new FeeTermsInput(
            validFrom: $payload->string('validFrom'),
            entryFeeRate: $payload->nullableString('entryFeeRate'),
            euroFundManagementFeeRate: $payload->nullableString('euroFundManagementFeeRate'),
            unitLinkedManagementFeeRate: $payload->nullableString('unitLinkedManagementFeeRate'),
            switchFeeRate: $payload->nullableString('switchFeeRate'),
            switchFeeFixed: OperationPayload::amountInput($payload, 'switchFeeFixed'),
            sourceNote: $payload->nullableString('sourceNote'),
        );
    }

    /**
     * @param array<mixed> $body
     *
     * @throws MalformedPayload
     */
    public static function euroFundRate(array $body): EuroFundRateInput
    {
        $payload = JsonPayload::of($body, ['instrumentId', 'year', 'servedRate', 'basis'], ['bonusRate', 'sourceNote']);

        return new EuroFundRateInput(
            instrumentId: $payload->string('instrumentId'),
            year: $payload->integer('year'),
            servedRate: $payload->string('servedRate'),
            bonusRate: $payload->nullableString('bonusRate'),
            basis: $payload->string('basis'),
            sourceNote: $payload->nullableString('sourceNote'),
        );
    }
}
