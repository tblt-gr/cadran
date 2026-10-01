<?php

declare(strict_types=1);

namespace App\Module\Portfolio\UI\Http;

use App\Module\Portfolio\Application\InstrumentCandidateView;
use App\Module\Portfolio\Application\InstrumentPage;
use App\Module\Portfolio\Application\InstrumentView;

final class InstrumentRepresentation
{
    /** @return array<string, mixed> */
    public static function one(InstrumentView $instrument): array
    {
        return [
            'id' => $instrument->id,
            'name' => $instrument->name,
            'isin' => $instrument->isin,
            'ticker' => $instrument->ticker,
            'venueMic' => $instrument->venueMic,
            'instrumentType' => $instrument->instrumentType,
            'assetClass' => $instrument->assetClass,
            'region' => $instrument->region,
            'currencyCode' => $instrument->currencyCode,
            'quantityPrecision' => $instrument->quantityPrecision,
            'pricePrecision' => $instrument->pricePrecision,
            'source' => $instrument->source,
            'reviewed' => $instrument->reviewed,
            'reviewedAt' => $instrument->reviewedAt,
            'status' => $instrument->status,
            'used' => $instrument->used,
            'version' => $instrument->version,
        ];
    }

    /** @return array<string, mixed> */
    public static function page(InstrumentPage $page): array
    {
        return [
            'items' => array_map(self::one(...), $page->items),
            'page' => $page->page,
            'perPage' => $page->perPage,
            'total' => $page->total,
        ];
    }

    /** @return array<string, mixed> */
    public static function candidate(InstrumentCandidateView $candidate): array
    {
        return [
            'id' => $candidate->id,
            'name' => $candidate->name,
            'isin' => $candidate->isin,
            'ticker' => $candidate->ticker,
            'status' => $candidate->status,
        ];
    }
}
