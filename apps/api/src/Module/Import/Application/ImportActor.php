<?php

declare(strict_types=1);

namespace App\Module\Import\Application;

use App\Module\Import\Domain\WorkbookUsageError;

/**
 * Makes the console act as one workspace owner, so every write goes through the
 * same use cases, workspace scoping and audit trail as an HTTP request.
 */
interface ImportActor
{
    /**
     * @param ?string $email the owner to act as, or null for the provisioned owner
     *
     * @throws WorkbookUsageError when no enabled owner can be found
     */
    public function assume(?string $email): void;
}
