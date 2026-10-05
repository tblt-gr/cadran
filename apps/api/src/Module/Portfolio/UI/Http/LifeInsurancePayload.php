<?php

declare(strict_types=1);

namespace App\Module\Portfolio\UI\Http;

use App\Module\Portfolio\Application\AmountInput;
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
     * @throws \UnexpectedValueException
     */
    public static function contract(array $body): LifeInsuranceContractInput
    {
        $fields = OperationPayload::shape(
            $body,
            ['insurer', 'subscribedOn', 'managementMode'],
            ['distributor', 'commercialName', 'contractNumber', 'taxStartOn', 'version'],
        );

        return new LifeInsuranceContractInput(
            insurer: self::requiredString($fields, 'insurer'),
            distributor: self::string($fields, 'distributor'),
            commercialName: self::string($fields, 'commercialName'),
            contractNumberProvided: array_key_exists('contractNumber', $fields),
            contractNumber: self::string($fields, 'contractNumber'),
            subscribedOn: self::requiredString($fields, 'subscribedOn'),
            taxStartOn: self::string($fields, 'taxStartOn'),
            managementMode: self::requiredString($fields, 'managementMode'),
            version: self::integer($fields, 'version'),
        );
    }

    /**
     * @param array<mixed> $body
     *
     * @throws \UnexpectedValueException
     */
    public static function feeTerms(array $body): FeeTermsInput
    {
        $fields = OperationPayload::shape(
            $body,
            ['validFrom'],
            ['entryFeeRate', 'euroFundManagementFeeRate', 'unitLinkedManagementFeeRate', 'switchFeeRate', 'switchFeeFixed', 'sourceNote'],
        );

        return new FeeTermsInput(
            validFrom: self::requiredString($fields, 'validFrom'),
            entryFeeRate: self::string($fields, 'entryFeeRate'),
            euroFundManagementFeeRate: self::string($fields, 'euroFundManagementFeeRate'),
            unitLinkedManagementFeeRate: self::string($fields, 'unitLinkedManagementFeeRate'),
            switchFeeRate: self::string($fields, 'switchFeeRate'),
            switchFeeFixed: self::amount($fields, 'switchFeeFixed'),
            sourceNote: self::string($fields, 'sourceNote'),
        );
    }

    /**
     * @param array<mixed> $body
     *
     * @throws \UnexpectedValueException
     */
    public static function euroFundRate(array $body): EuroFundRateInput
    {
        $fields = OperationPayload::shape($body, ['instrumentId', 'year', 'servedRate', 'basis'], ['bonusRate', 'sourceNote']);

        return new EuroFundRateInput(
            instrumentId: self::requiredString($fields, 'instrumentId'),
            year: self::integer($fields, 'year') ?? throw new \UnexpectedValueException('year is required.'),
            servedRate: self::requiredString($fields, 'servedRate'),
            bonusRate: self::string($fields, 'bonusRate'),
            basis: self::requiredString($fields, 'basis'),
            sourceNote: self::string($fields, 'sourceNote'),
        );
    }

    /** @param array<string, mixed> $fields */
    private static function requiredString(array $fields, string $name): string
    {
        return self::string($fields, $name) ?? throw new \UnexpectedValueException(sprintf('%s is required.', $name));
    }

    /** @param array<string, mixed> $fields */
    private static function string(array $fields, string $name): ?string
    {
        $value = $fields[$name] ?? null;
        if (null !== $value && !is_string($value)) {
            throw new \UnexpectedValueException(sprintf('%s must be a string.', $name));
        }

        return $value;
    }

    /** @param array<string, mixed> $fields */
    private static function integer(array $fields, string $name): ?int
    {
        $value = $fields[$name] ?? null;
        if (null !== $value && !is_int($value)) {
            throw new \UnexpectedValueException(sprintf('%s must be an integer.', $name));
        }

        return $value;
    }

    /** @param array<string, mixed> $fields */
    private static function amount(array $fields, string $name): ?AmountInput
    {
        $value = $fields[$name] ?? null;
        if (null === $value) {
            return null;
        }
        if (!is_array($value)) {
            throw new \UnexpectedValueException(sprintf('%s must be an amount.', $name));
        }
        $amount = OperationPayload::shape($value, ['value', 'assetCode']);
        if (!is_string($amount['value']) || !is_string($amount['assetCode'])) {
            throw new \UnexpectedValueException(sprintf('%s is a value and an asset code.', $name));
        }

        return new AmountInput($amount['value'], $amount['assetCode']);
    }
}
