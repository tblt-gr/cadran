<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application\LifeInsurance;

use App\Module\Audit\Domain\AuditDiff;
use App\Module\Portfolio\Domain\LifeInsurance\EuroFundRate;
use App\Module\Portfolio\Domain\LifeInsurance\FeeTerms;
use App\Module\Portfolio\Domain\LifeInsurance\LifeInsuranceContract;

/** Identifiers, dates, years and field names only: no insurer, number, rate or amount ever enters the trail. */
final class LifeInsuranceAudit
{
    public static function contractCreated(LifeInsuranceContract $contract): AuditDiff
    {
        return AuditDiff::creation([
            'subscribedOn' => $contract->subscribedOn->format('Y-m-d'),
            'status' => $contract->status->value,
            'version' => (string) $contract->version,
        ]);
    }

    /** @param list<string> $fields */
    public static function contractUpdated(array $fields, int $version): AuditDiff
    {
        $old = [];
        $new = ['version' => (string) $version];
        foreach ($fields as $field) {
            $old[$field] = 'unchanged';
            $new[$field] = 'changed';
        }

        return AuditDiff::change($old, $new);
    }

    public static function feeTerms(FeeTerms $terms): AuditDiff
    {
        return AuditDiff::creation(['contractId' => $terms->contractId, 'validFrom' => $terms->validFrom->format('Y-m-d')]);
    }

    public static function removedFeeTerms(FeeTerms $terms): AuditDiff
    {
        return AuditDiff::change(['contractId' => $terms->contractId, 'validFrom' => $terms->validFrom->format('Y-m-d')], []);
    }

    public static function rate(EuroFundRate $rate): AuditDiff
    {
        return AuditDiff::creation(['contractId' => $rate->contractId, 'instrumentId' => $rate->instrumentId, 'year' => (string) $rate->year]);
    }

    public static function removedRate(EuroFundRate $rate): AuditDiff
    {
        return AuditDiff::change(['contractId' => $rate->contractId, 'instrumentId' => $rate->instrumentId, 'year' => (string) $rate->year], []);
    }
}
