<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

/**
 * The account is archived and therefore read-only. Distinguished from an
 * invalid field so a stale tab is told what actually happened rather than
 * being sent to re-check dates that were never the problem.
 */
final class AccountArchived extends InvalidAccountInput
{
}
