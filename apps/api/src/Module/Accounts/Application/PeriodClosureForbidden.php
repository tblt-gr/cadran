<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

/** Only the workspace owner overrides a closing condition or reopens a period. */
final class PeriodClosureForbidden extends \RuntimeException
{
}
