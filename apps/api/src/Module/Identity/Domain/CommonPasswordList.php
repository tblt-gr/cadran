<?php

declare(strict_types=1);

namespace App\Module\Identity\Domain;

/**
 * Port to a list of passwords known from public breaches or dictionaries (OWASP ASVS 5.0 V6.2.4). The data
 * lives in infrastructure; the policy only asks whether a candidate is on it.
 */
interface CommonPasswordList
{
    /** Case-insensitive. */
    public function contains(string $candidate): bool;
}
