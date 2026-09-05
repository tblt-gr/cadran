<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

/**
 * The workspace holds more accounts than one aggregate may read at once.
 *
 * Refusing is the bounded answer: silently truncating the set would publish a
 * net worth that looks complete and is not.
 */
final class NetWorthScopeTooLarge extends \RuntimeException
{
}
