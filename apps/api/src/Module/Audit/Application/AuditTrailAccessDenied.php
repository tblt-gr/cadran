<?php

declare(strict_types=1);

namespace App\Module\Audit\Application;

/**
 * Raised when the caller has no workspace to read a trail in. The reader never
 * falls back to "all workspaces": an unattributable caller reads nothing.
 */
final class AuditTrailAccessDenied extends \RuntimeException
{
}
