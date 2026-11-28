<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

/** The workspace already holds as many groups as one tree read covers. */
final class AccountGroupLimitReached extends AccountGroupConflict
{
}
