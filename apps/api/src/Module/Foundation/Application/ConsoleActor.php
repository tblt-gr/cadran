<?php

declare(strict_types=1);

namespace App\Module\Foundation\Application;

/**
 * Makes a console command act as one workspace owner, so every write goes
 * through the same use cases, workspace scoping and audit trail as an HTTP
 * request.
 */
interface ConsoleActor
{
    /**
     * @param ?string $email the owner to act as, or null for the provisioned owner
     *
     * @throws ConsoleActorRefused when no enabled owner can be found
     */
    public function assume(?string $email): void;
}
