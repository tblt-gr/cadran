<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

/**
 * The active sibling order the caller displayed is not the saved one. The
 * caller reloads the groups and reapplies its move.
 */
final class StaleAccountGroupOrder extends \RuntimeException
{
}
