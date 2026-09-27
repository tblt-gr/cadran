<?php

declare(strict_types=1);

namespace App\Module\Demo\Application;

/**
 * Makes the console act as one workspace owner, so every write goes through
 * the same use cases, workspace scoping and audit trail as an HTTP request.
 */
interface DemoActor
{
    /**
     * @param ?string $email the owner to act as, or null for the provisioned owner
     *
     * @throws DemoSeedRefused when no such owner can be found
     */
    public function assume(?string $email): void;
}
