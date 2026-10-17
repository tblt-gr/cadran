<?php

declare(strict_types=1);

namespace App\Module\Import\Domain;

use Symfony\Polyfill\Intl\Normalizer\Normalizer;

/**
 * The comparison form of every label, header and sheet name of a workbook:
 * lowercase, accents removed through NFKD, every run of other characters
 * folded into one hyphen. Mapping keys and sheet names go through the same
 * function, so `Dépenses Fixes :` and `depenses fixes` meet.
 */
final class LabelKey
{
    public static function normalize(string $label): string
    {
        $decomposed = Normalizer::normalize($label, Normalizer::FORM_KD);
        if (!is_string($decomposed)) {
            // Not valid UTF-8: no safe comparison form exists, and an empty key is treated as an empty label.
            return '';
        }

        $withoutMarks = preg_replace('/\p{Mn}+/u', '', $decomposed) ?? '';
        $folded = preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($withoutMarks, 'UTF-8')) ?? '';

        return trim($folded, '-');
    }
}
