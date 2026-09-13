<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application\Recurrence;

final class StaleRecurrence extends \RuntimeException
{
    public const string ARCHIVED = 'archived';
}
