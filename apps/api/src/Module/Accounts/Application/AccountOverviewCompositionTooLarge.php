<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

/** The workspace holds more eligible accounts than one overview composition reads. */
final class AccountOverviewCompositionTooLarge extends \RuntimeException
{
}
