<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

/**
 * Where an observed balance came from. The set is closed so a later provider
 * cannot be invented as a free-text tag: each source is a distinct claim about
 * the same day, and uniqueness is enforced per source.
 */
enum BalanceSnapshotSource: string
{
    case MANUAL = 'MANUAL';
    case IMPORT = 'IMPORT';
    case BANK_API = 'BANK_API';
    case CALCULATED = 'CALCULATED';
}
