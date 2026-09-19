<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

/**
 * The one refusal every guarded write raises. It names neither the month nor
 * the use case, so a caller cannot learn which periods are closed by probing.
 */
final class PeriodClosed extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('The period this operation touches is closed.');
    }
}
