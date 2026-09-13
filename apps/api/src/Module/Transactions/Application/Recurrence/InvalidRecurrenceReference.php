<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application\Recurrence;

/** The account or asset a recurrence names is unknown, archived or outside the caller's workspace. */
final class InvalidRecurrenceReference extends \RuntimeException
{
}
