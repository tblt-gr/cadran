<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

/**
 * The submitted version is not the stored one: someone else changed the account
 * between the read and the write.
 *
 * It is kept apart from a label conflict because the two carry opposite advice.
 * Here the caller must reload and reapply its change; there it must pick another
 * label. Answering both with one message leaves a user retrying forever.
 */
final class StaleAccountVersion extends AccountConflict
{
}
