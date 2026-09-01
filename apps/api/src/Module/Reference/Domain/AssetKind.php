<?php

declare(strict_types=1);

namespace App\Module\Reference\Domain;

/**
 * What kind of unit a figure is denominated in. Kept deliberately short: only
 * the families the reference actually holds today, so an unknown value cannot
 * enter the catalogue and be interpreted later by guesswork.
 */
enum AssetKind: string
{
    case FIAT = 'FIAT';
    case CRYPTO = 'CRYPTO';
}
