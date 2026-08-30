<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

/**
 * Raw provisioning request. Shape and business rules are enforced by the
 * Identity domain entities the use case builds from it, not here.
 */
final readonly class InitialWorkspaceProvisioningInput
{
    public function __construct(
        public string $email,
        public string $displayName,
        public string $workspaceName,
        public string $baseCurrency,
    ) {
    }
}
