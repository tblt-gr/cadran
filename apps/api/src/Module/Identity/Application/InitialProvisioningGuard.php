<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

interface InitialProvisioningGuard
{
    /**
     * Atomically claims the one-time provisioning latch.
     *
     * @return bool true when this caller acquired it, false when it was already taken
     */
    public function tryAcquire(): bool;
}
