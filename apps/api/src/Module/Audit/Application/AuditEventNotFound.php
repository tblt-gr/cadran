<?php

declare(strict_types=1);

namespace App\Module\Audit\Application;

/**
 * Raised when the requested event is absent from the caller's workspace. It
 * carries no hint about whether the identifier exists elsewhere.
 */
final class AuditEventNotFound extends \RuntimeException
{
}
