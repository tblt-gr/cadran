<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

/**
 * Tells whether the running write comes from a person using the application. A console command
 * (the demo seed, a migration, a job) records a fact that is planned or past, so it is never refused
 * for the balance it leaves: only an interactive write is.
 */
interface InteractiveWriteContext
{
    public function isInteractive(): bool;
}
