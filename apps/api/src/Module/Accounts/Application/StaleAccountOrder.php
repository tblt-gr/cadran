<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

/**
 * The order of the group changed between the read and the write. The caller
 * reloads the saved order and reapplies its move.
 */
final class StaleAccountOrder extends \RuntimeException
{
}
