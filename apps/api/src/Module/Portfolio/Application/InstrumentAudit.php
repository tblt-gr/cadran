<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Audit\Domain\AuditDiff;
use App\Module\Portfolio\Domain\Instrument;

final class InstrumentAudit
{
    public static function created(Instrument $instrument): AuditDiff
    {
        return AuditDiff::creation([
            'instrumentType' => $instrument->instrumentType->value,
            'assetClass' => $instrument->assetClass?->value,
            'region' => $instrument->region?->value,
            'currencyCode' => $instrument->currencyCode->toString(),
            'status' => $instrument->status->value,
            'version' => $instrument->version,
        ]);
    }

    /** Names only: values such as identifiers and user metadata do not belong in the trail. */
    public static function changedFields(Instrument $before, Instrument $after): AuditDiff
    {
        $beforeValues = self::values($before);
        $afterValues = self::values($after);
        $old = [];
        $new = [];
        foreach ($beforeValues as $field => $value) {
            if ($value !== $afterValues[$field]) {
                $old[$field] = 'unchanged';
                $new[$field] = 'changed';
            }
        }

        return AuditDiff::change($old, $new);
    }

    /** @return array<string, scalar|null> */
    private static function values(Instrument $instrument): array
    {
        return [
            'name' => $instrument->name,
            'isin' => $instrument->isin?->toString(),
            'ticker' => $instrument->ticker?->toString(),
            'venueMic' => $instrument->venueMic?->toString(),
            'instrumentType' => $instrument->instrumentType->value,
            'assetClass' => $instrument->assetClass?->value,
            'region' => $instrument->region?->value,
            'currencyCode' => $instrument->currencyCode->toString(),
            'quantityPrecision' => $instrument->quantityPrecision,
            'pricePrecision' => $instrument->pricePrecision,
        ];
    }
}
