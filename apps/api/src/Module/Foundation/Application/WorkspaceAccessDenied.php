<?php

declare(strict_types=1);

namespace App\Module\Foundation\Application;

/**
 * Raised when a caller has no workspace to act in. There is no fallback to
 * "every workspace": an unattributable caller reads and writes nothing.
 */
final class WorkspaceAccessDenied extends \RuntimeException
{
}
