<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

/**
 * Rebinds the caller's own session to a credential that has just changed.
 *
 * A password change invalidates every session bound to the old hash, including
 * the one that made the request. Renewing it keeps the acting browser signed in
 * — the acceptance criterion — while rotating the session identifier, so a
 * cookie captured before the change cannot be replayed after it.
 */
interface CurrentSessionRenewal
{
    public function renew(): void;
}
