<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

/** The subtree of the group holds more accounts than one composition reads. */
final class GroupCompositionTooLarge extends \RuntimeException
{
}
